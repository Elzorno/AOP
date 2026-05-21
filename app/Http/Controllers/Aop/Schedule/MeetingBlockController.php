<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Enums\SectionModality;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\Room;
use App\Models\ScheduleChangeLog;
use App\Models\Section;
use App\Models\Term;
use App\Services\MeetingBlockMutationService;
use App\Services\ScheduleConflictService;
use App\Services\ScheduleRuleWarningService;
use App\Services\SectionCardBuilderService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeetingBlockController extends Controller
{
    private const ALLOWED_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    private function ensureSectionInActiveTerm(Section $section): Term
    {
        $term = $this->activeTermOrFail();
        $section->load('offering');
        abort_if($section->offering->term_id !== $term->id, 400, 'Section not in active term.');
        return $term;
    }

    private function ensureScheduleUnlocked(Term $term): void
    {
        abort_if($term->schedule_locked, 403, 'Schedule is locked for the active term. Unlock it before making schedule changes.');
    }

    private function sectionCardResponse(Section $section, Term $term, ?string $htmxError = null, ?int $openBlockId = null): Response
    {
        $section->load(['meetingBlocks.room', 'instructor', 'offering.catalogCourse']);
        $sectionCard = app(SectionCardBuilderService::class)->build($section, $term);

        return response()->view('aop.schedule.partials.section-card', [
            'section'          => $section,
            'sectionCard'      => $sectionCard,
            'course'           => $section->offering->catalogCourse,
            'rooms'            => Room::orderBy('name')->get(),
            'instructors'      => Instructor::orderBy('name')->get(),
            'modalities'       => SectionModality::cases(),
            'meetingBlockTypes'=> MeetingBlockType::cases(),
            'weekDays'         => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
            'scheduleLocked'   => (bool) $term->schedule_locked,
            'focusSectionId'   => null,
            'dayAbbr'          => ['Monday'=>'Mon','Tuesday'=>'Tue','Wednesday'=>'Wed','Thursday'=>'Thu','Friday'=>'Fri','Saturday'=>'Sat','Sunday'=>'Sun'],
            'htmxError'        => $htmxError,
            'oldMeetingBlockId'=> $openBlockId,
        ]);
    }

    public function store(Request $request, Section $section, ScheduleConflictService $conflicts, ScheduleRuleWarningService $ruleWarnings)
    {
        $term = $this->ensureSectionInActiveTerm($section);
        $this->ensureScheduleUnlocked($term);

        $data = $request->validate([
            'type' => ['required', Rule::enum(MeetingBlockType::class)],
            'days' => ['required','array','min:1'],
            'days.*' => ['string', Rule::in(self::ALLOWED_DAYS), 'distinct'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'room_id' => ['nullable','integer','exists:rooms,id'],
            'notes' => ['nullable','string','max:2000'],
        ]);

        $isHtmx = (bool) $request->header('HX-Request');

        if ($data['ends_at'] <= $data['starts_at']) {
            if ($isHtmx) return $this->sectionCardResponse($section, $term, 'End time must be after start time.');
            return back()->withErrors(['ends_at' => 'End time must be after start time.'])->withInput();
        }

        if ($section->modality->value === 'ONLINE') {
            $data['room_id'] = null;
        } else {
            if (empty($data['room_id'])) {
                if ($isHtmx) return $this->sectionCardResponse($section, $term, 'Room is required for in-person or hybrid sections.');
                return back()->withErrors(['room_id' => 'Room is required for in-person or hybrid sections.'])->withInput();
            }
        }

        $days = array_values(array_unique($data['days']));

        // Room conflicts: class vs class only
        $roomConflicts = $conflicts->roomConflictsForMeetingBlock($term, (int)($data['room_id'] ?? 0), $days, $data['starts_at'], $data['ends_at']);

        if ($roomConflicts->count() > 0) {
            $msg = 'Room conflict with: ' . $roomConflicts->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
            if ($isHtmx) return $this->sectionCardResponse($section, $term, $msg);
            return back()->withErrors(['conflicts' => $msg])->withInput();
        }

        // Instructor conflicts: class vs class + office hours
        if (!empty($section->instructor_id)) {
            $insConf = $conflicts->instructorConflictsForMeetingBlock($term, (int)$section->instructor_id, $days, $data['starts_at'], $data['ends_at']);
            $messages = [];

            if ($insConf['meeting_blocks']->count() > 0) {
                $messages[] = 'Instructor conflict with classes: ' . $insConf['meeting_blocks']->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
            }

            if ($insConf['office_hour_blocks']->count() > 0) {
                $messages[] = 'Instructor conflict with office hours: ' . $insConf['office_hour_blocks']->map(fn($ob) => ScheduleConflictService::formatOfficeHourLabel($ob))->implode('; ');
            }

            if (!empty($messages)) {
                $msg = implode(' | ', $messages);
                if ($isHtmx) return $this->sectionCardResponse($section, $term, $msg);
                return back()->withErrors(['conflicts' => $msg])->withInput();
            }
        }

        // Rule warnings (soft or hard depending on term enforcement flag)
        $ruleWarns = $ruleWarnings->warnForBlock($days, $data['starts_at'], $data['ends_at'], $data['type'], $term);
        if (!empty($ruleWarns)) {
            if ($ruleWarnings->isEnforced($term)) {
                $msg = implode(' | ', $ruleWarns);
                if ($isHtmx) return $this->sectionCardResponse($section, $term, $msg);
                return back()->withErrors(['schedule_rules' => $msg])->withInput();
            }
            session()->flash('schedule_warnings', $ruleWarns);
        }

        $block = MeetingBlock::create([
            'section_id' => $section->id,
            'type' => $data['type'],
            'days_json' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'room_id' => $data['room_id'],
            'notes' => $data['notes'] ?? null,
        ]);

        ScheduleChangeLog::create([
            'term_id'          => $term->id,
            'section_id'       => $section->id,
            'meeting_block_id' => $block->id,
            'user_id'          => auth()->id(),
            'action'           => 'created',
            'payload_before'   => null,
            'payload_after'    => $block->only(['type','days_json','starts_at','ends_at','room_id','notes']),
        ]);

        if ($isHtmx) return $this->sectionCardResponse($section, $term);

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['focus' => $section->id])->with('status', 'Meeting block added.');
        }

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Meeting block added.');
    }

    public function destroy(Request $request, Section $section, MeetingBlock $meetingBlock)
    {
        $term = $this->ensureSectionInActiveTerm($section);
        $this->ensureScheduleUnlocked($term);
        abort_if($meetingBlock->section_id !== $section->id, 400, 'Meeting block not in section.');

        $before = $meetingBlock->only(['type','days_json','starts_at','ends_at','room_id','notes']);
        $blockId = $meetingBlock->id;

        $meetingBlock->delete();

        ScheduleChangeLog::create([
            'term_id'          => $term->id,
            'section_id'       => $section->id,
            'meeting_block_id' => null,   // already deleted; nulled by FK
            'user_id'          => auth()->id(),
            'action'           => 'deleted',
            'payload_before'   => $before,
            'payload_after'    => null,
        ]);

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['focus' => $section->id])->with('status', 'Meeting block removed.');
        }

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Meeting block removed.');
    }

    public function update(Request $request, Section $section, MeetingBlock $meetingBlock, MeetingBlockMutationService $mutationService, ScheduleRuleWarningService $ruleWarnings)
    {
        $term = $this->ensureSectionInActiveTerm($section);
        abort_if($meetingBlock->section_id !== $section->id, 400, 'Meeting block not in section.');

        $data = $request->validate([
            'type' => ['required', Rule::enum(MeetingBlockType::class)],
            'days' => ['required','array','min:1'],
            'days.*' => ['string', Rule::in(self::ALLOWED_DAYS), 'distinct'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'room_id' => ['nullable','integer','exists:rooms,id'],
            'notes' => ['nullable','string','max:2000'],
        ]);

        $isHtmx = (bool) $request->header('HX-Request');

        // Rule warnings (soft or hard depending on term enforcement flag)
        $days = array_values(array_unique($data['days']));
        $ruleWarns = $ruleWarnings->warnForBlock($days, $data['starts_at'], $data['ends_at'], $data['type'], $term);
        if (!empty($ruleWarns)) {
            if ($ruleWarnings->isEnforced($term)) {
                $msg = implode(' | ', $ruleWarns);
                if ($isHtmx) return $this->sectionCardResponse($section, $term, $msg, $meetingBlock->id);
                return back()->withErrors(['schedule_rules' => $msg])->withInput();
            }
            session()->flash('schedule_warnings', $ruleWarns);
        }

        $before = $meetingBlock->only(['type','days_json','starts_at','ends_at','room_id','notes']);

        try {
            $mutationService->update($term, $meetingBlock, [
                'type' => $data['type'],
                'days' => $data['days'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'room_id' => $data['room_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (ValidationException $e) {
            $msg = implode(' | ', array_merge(...array_values($e->errors())));
            if ($isHtmx) return $this->sectionCardResponse($section, $term, $msg, $meetingBlock->id);
            return back()->withErrors($e->errors())->withInput();
        }

        $meetingBlock->refresh();
        ScheduleChangeLog::create([
            'term_id'          => $term->id,
            'section_id'       => $section->id,
            'meeting_block_id' => $meetingBlock->id,
            'user_id'          => auth()->id(),
            'action'           => 'updated',
            'payload_before'   => $before,
            'payload_after'    => $meetingBlock->only(['type','days_json','starts_at','ends_at','room_id','notes']),
        ]);

        if ($isHtmx) return $this->sectionCardResponse($section, $term, null, null);

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['focus' => $section->id])->with('status', 'Meeting block updated.');
        }

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Meeting block updated.');
    }
}

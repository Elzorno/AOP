<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Data\Scheduling\MeetingBlockCommand;
use App\Enums\MeetingBlockType;
use App\Enums\SectionModality;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use App\Services\MeetingBlockMutationService;
use App\Services\ScheduleRuleWarningService;
use App\Services\SectionCardBuilderService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeetingBlockController extends Controller
{
    private const ALLOWED_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    private function ensureSectionInTerm(Term $term, Section $section): void
    {
        $section->load('offering');
        abort_if($section->offering->term_id !== $term->id, 404, 'Section not in this term.');
    }

    private function sectionCardResponse(Section $section, Term $term, ?string $htmxError = null, ?int $openBlockId = null): Response
    {
        $section->load(['meetingBlocks.room', 'instructor', 'offering.catalogCourse']);
        $sectionCard = app(SectionCardBuilderService::class)->build($section, $term);

        return response()->view('aop.schedule.partials.section-card', [
            'term' => $term,
            'section' => $section,
            'sectionCard' => $sectionCard,
            'course' => $section->offering->catalogCourse,
            'rooms' => Room::orderBy('name')->get(),
            'instructors' => Instructor::orderBy('name')->get(),
            'modalities' => SectionModality::cases(),
            'meetingBlockTypes' => MeetingBlockType::cases(),
            'weekDays' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'scheduleLocked' => false,
            'focusSectionId' => null,
            'dayAbbr' => ['Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed', 'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat', 'Sunday' => 'Sun'],
            'htmxError' => $htmxError,
            'oldMeetingBlockId' => $openBlockId,
        ]);
    }

    public function store(Request $request, Term $term, Section $section, MeetingBlockMutationService $mutationService, ScheduleRuleWarningService $ruleWarnings)
    {
        $this->ensureSectionInTerm($term, $section);

        $data = $request->validate([
            'type' => ['required', Rule::enum(MeetingBlockType::class)],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['string', Rule::in(self::ALLOWED_DAYS), 'distinct'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $isHtmx = (bool) $request->header('HX-Request');

        $days = array_values(array_unique($data['days']));

        // Rule warnings (soft or hard depending on term enforcement flag)
        $ruleWarns = $ruleWarnings->warnForBlock($days, $data['starts_at'], $data['ends_at'], $data['type'], $term);
        if (! empty($ruleWarns)) {
            if ($ruleWarnings->isEnforced($term)) {
                $msg = implode(' | ', $ruleWarns);
                if ($isHtmx) {
                    return $this->sectionCardResponse($section, $term, $msg);
                }

                return back()->withErrors(['schedule_rules' => $msg])->withInput();
            }
            session()->flash('schedule_warnings', $ruleWarns);
        }

        try {
            $mutationService->create($term, $section, MeetingBlockCommand::fromArray($data));
        } catch (ValidationException $e) {
            $msg = implode(' | ', array_merge(...array_values($e->errors())));
            if ($isHtmx) {
                return $this->sectionCardResponse($section, $term, $msg);
            }

            return back()->withErrors($e->errors())->withInput();
        }

        if ($isHtmx) {
            return $this->sectionCardResponse($section, $term);
        }

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['term' => $term, 'focus' => $section->id])->with('status', 'Meeting block added.');
        }

        return redirect()->route('aop.schedule.sections.edit', [$term, $section])->with('status', 'Meeting block added.');
    }

    public function destroy(Request $request, Term $term, Section $section, MeetingBlock $meetingBlock, MeetingBlockMutationService $mutationService)
    {
        $this->ensureSectionInTerm($term, $section);
        abort_if($meetingBlock->section_id !== $section->id, 404, 'Meeting block not in section.');

        $mutationService->delete($term, $section, $meetingBlock);

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['term' => $term, 'focus' => $section->id])->with('status', 'Meeting block removed.');
        }

        return redirect()->route('aop.schedule.sections.edit', [$term, $section])->with('status', 'Meeting block removed.');
    }

    public function update(Request $request, Term $term, Section $section, MeetingBlock $meetingBlock, MeetingBlockMutationService $mutationService, ScheduleRuleWarningService $ruleWarnings)
    {
        $this->ensureSectionInTerm($term, $section);
        abort_if($meetingBlock->section_id !== $section->id, 404, 'Meeting block not in section.');

        $data = $request->validate([
            'type' => ['required', Rule::enum(MeetingBlockType::class)],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['string', Rule::in(self::ALLOWED_DAYS), 'distinct'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $isHtmx = (bool) $request->header('HX-Request');

        // Rule warnings (soft or hard depending on term enforcement flag)
        $days = array_values(array_unique($data['days']));
        $ruleWarns = $ruleWarnings->warnForBlock($days, $data['starts_at'], $data['ends_at'], $data['type'], $term);
        if (! empty($ruleWarns)) {
            if ($ruleWarnings->isEnforced($term)) {
                $msg = implode(' | ', $ruleWarns);
                if ($isHtmx) {
                    return $this->sectionCardResponse($section, $term, $msg, $meetingBlock->id);
                }

                return back()->withErrors(['schedule_rules' => $msg])->withInput();
            }
            session()->flash('schedule_warnings', $ruleWarns);
        }

        try {
            $mutationService->update($term, $meetingBlock, MeetingBlockCommand::fromArray([
                'type' => $data['type'],
                'days' => $data['days'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'room_id' => $data['room_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]));
        } catch (ValidationException $e) {
            $msg = implode(' | ', array_merge(...array_values($e->errors())));
            if ($isHtmx) {
                return $this->sectionCardResponse($section, $term, $msg, $meetingBlock->id);
            }

            return back()->withErrors($e->errors())->withInput();
        }

        if ($isHtmx) {
            return $this->sectionCardResponse($section, $term, null, null);
        }

        if ($request->boolean('from_schedule_home')) {
            return redirect()->route('aop.schedule.home', ['term' => $term, 'focus' => $section->id])->with('status', 'Meeting block updated.');
        }

        return redirect()->route('aop.schedule.sections.edit', [$term, $section])->with('status', 'Meeting block updated.');
    }
}

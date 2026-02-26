<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\MeetingBlock;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleConflictService;
use Illuminate\Http\Request;

class MeetingBlockController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    private function ensureTermUnlocked(Term $term)
    {
        if ($term->schedule_locked) {
            abort(403, 'Schedule is locked for the active term. Unlock the term schedule to make changes.');
        }
    }

    private function ensureSectionInActiveTerm(Section $section): Term
    {
        $term = $this->activeTermOrFail();
        $section->load('offering');
        abort_if($section->offering->term_id !== $term->id, 400, 'Section not in active term.');
        return $term;
    }

    public function store(Request $request, Section $section, ScheduleConflictService $conflicts)
    {
        $term = $this->ensureSectionInActiveTerm($section);
        $this->ensureTermUnlocked($term);

        $data = $request->validate([
            'type' => ['required','string'],
            'days' => ['required','array','min:1'],
            'days.*' => ['string'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'room_id' => ['nullable','integer','exists:rooms,id'],
            'notes' => ['nullable','string'],
        ]);

        if ($data['ends_at'] <= $data['starts_at']) {
            return back()->withErrors(['ends_at' => 'End time must be after start time.'])->withInput();
        }

        if ($section->modality->value === 'ONLINE') {
            $data['room_id'] = null;
        } else {
            if (empty($data['room_id'])) {
                return back()->withErrors(['room_id' => 'Room is required for in-person or hybrid sections.'])->withInput();
            }
        }

        $days = array_values($data['days']);

        // Room conflicts: class vs class only
        $roomConflicts = $conflicts->roomConflictsForMeetingBlock($term, (int)($data['room_id'] ?? 0), $days, $data['starts_at'], $data['ends_at']);

        if ($roomConflicts->count() > 0) {
            $msg = 'Room conflict with: ' . $roomConflicts->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
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
                return back()->withErrors(['conflicts' => implode(' | ', $messages)])->withInput();
            }
        }

        MeetingBlock::create([
            'section_id' => $section->id,
            'type' => $data['type'],
            'days_json' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'room_id' => $data['room_id'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Meeting block added.');
    }

    public function update(Request $request, Section $section, MeetingBlock $meetingBlock, ScheduleConflictService $conflicts)
    {
        $term = $this->ensureSectionInActiveTerm($section);
        $this->ensureTermUnlocked($term);

        abort_if($meetingBlock->section_id !== $section->id, 400, 'Meeting block not in section.');

        $data = $request->validate([
            'type' => ['required','string'],
            'days' => ['required','array','min:1'],
            'days.*' => ['string'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i'],
            'room_id' => ['nullable','integer','exists:rooms,id'],
            'notes' => ['nullable','string'],
        ]);

        if ($data['ends_at'] <= $data['starts_at']) {
            return back()->withErrors(['ends_at' => 'End time must be after start time.'])->withInput();
        }

        if ($section->modality->value === 'ONLINE') {
            $data['room_id'] = null;
        } else {
            if (empty($data['room_id'])) {
                return back()->withErrors(['room_id' => 'Room is required for in-person or hybrid sections.'])->withInput();
            }
        }

        $days = array_values($data['days']);

        // Room conflicts: class vs class only
        $roomConflicts = $conflicts->roomConflictsForMeetingBlock($term, (int)($data['room_id'] ?? 0), $days, $data['starts_at'], $data['ends_at'], $meetingBlock->id);

        if ($roomConflicts->count() > 0) {
            $msg = 'Room conflict with: ' . $roomConflicts->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
            return back()->withErrors(['conflicts' => $msg])->withInput();
        }

        // Instructor conflicts: class vs class + office hours
        if (!empty($section->instructor_id)) {
            $insConf = $conflicts->instructorConflictsForMeetingBlock($term, (int)$section->instructor_id, $days, $data['starts_at'], $data['ends_at'], $meetingBlock->id);
            $messages = [];

            if ($insConf['meeting_blocks']->count() > 0) {
                $messages[] = 'Instructor conflict with classes: ' . $insConf['meeting_blocks']->map(fn($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))->implode('; ');
            }

            if ($insConf['office_hour_blocks']->count() > 0) {
                $messages[] = 'Instructor conflict with office hours: ' . $insConf['office_hour_blocks']->map(fn($ob) => ScheduleConflictService::formatOfficeHourLabel($ob))->implode('; ');
            }

            if (!empty($messages)) {
                return back()->withErrors(['conflicts' => implode(' | ', $messages)])->withInput();
            }
        }

        $meetingBlock->update([
            'type' => $data['type'],
            'days_json' => $days,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'room_id' => $data['room_id'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('aop.schedule.sections.edit', $section)->with('status', 'Meeting block updated.');
    }
}

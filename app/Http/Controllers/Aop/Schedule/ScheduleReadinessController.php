<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleConflictService;

class ScheduleReadinessController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    public function index()
    {
        $term = $this->activeTermOrFail();

        $sections = Section::query()
            ->with(['offering.catalogCourse', 'instructor', 'meetingBlocks.room'])
            ->whereHas('offering', fn($q) => $q->where('term_id', $term->id))
            ->orderBy('id','desc')
            ->get();

        $sectionsMissingInstructor = $sections->filter(fn($s) => !$s->instructor_id)->values();
        $sectionsMissingMeetingBlocks = $sections->filter(fn($s) => $s->meetingBlocks->count() === 0)->values();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereHas('section.offering', fn($q) => $q->where('term_id', $term->id))
            ->orderBy('starts_at')
            ->get();

        $meetingBlocksMissingRoom = $meetingBlocks->filter(fn($mb) => !$mb->room_id)->values();

        $officeBlocks = OfficeHourBlock::query()
            ->with('instructor')
            ->where('term_id', $term->id)
            ->orderBy('starts_at')
            ->get();

        $roomConflicts = $this->computeRoomConflicts($meetingBlocks);
        $instructorConflicts = $this->computeInstructorConflicts($meetingBlocks, $officeBlocks);

        return view('aop.schedule.readiness.index', [
            'term' => $term,
            'sectionsMissingInstructor' => $sectionsMissingInstructor,
            'sectionsMissingMeetingBlocks' => $sectionsMissingMeetingBlocks,
            'meetingBlocksMissingRoom' => $meetingBlocksMissingRoom,
            'roomConflicts' => $roomConflicts,
            'instructorConflicts' => $instructorConflicts,
        ]);
    }

    private function computeRoomConflicts($meetingBlocks)
    {
        $conflicts = [];
        $byRoom = $meetingBlocks->whereNotNull('room_id')->groupBy('room_id');

        foreach ($byRoom as $roomId => $blocks) {
            $list = $blocks->values();
            $n = $list->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;

                    $conflicts[] = [
                        'room' => $a->room,
                        'a' => $a,
                        'b' => $b,
                    ];
                }
            }
        }

        return $conflicts;
    }

    private function computeInstructorConflicts($meetingBlocks, $officeBlocks)
    {
        $conflicts = [];

        $meetingByInstructor = $meetingBlocks
            ->filter(fn($mb) => (bool)$mb->section?->instructor_id)
            ->groupBy(fn($mb) => (int)$mb->section->instructor_id);

        $officeByInstructor = $officeBlocks->groupBy('instructor_id');

        $allInstructorIds = collect(array_unique(array_merge(
            $meetingByInstructor->keys()->all(),
            $officeByInstructor->keys()->all()
        )));

        foreach ($allInstructorIds as $insId) {
            $classList = ($meetingByInstructor[$insId] ?? collect())->values();
            $officeList = ($officeByInstructor[$insId] ?? collect())->values();

            // class vs class
            $n = $classList->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $classList[$i];
                    $b = $classList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $conflicts[] = [
                        'instructor' => $a->section->instructor,
                        'type' => 'CLASS vs CLASS',
                        'a_label' => ScheduleConflictService::formatMeetingBlockLabel($a),
                        'b_label' => ScheduleConflictService::formatMeetingBlockLabel($b),
                        'a_section_id' => $a->section_id,
                        'b_section_id' => $b->section_id,
                    ];
                }
            }

            // office vs office
            $m = $officeList->count();
            for ($i = 0; $i < $m; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $a = $officeList[$i];
                    $b = $officeList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $conflicts[] = [
                        'instructor' => $a->instructor,
                        'type' => 'OFFICE vs OFFICE',
                        'a_label' => ScheduleConflictService::formatOfficeHourLabel($a),
                        'b_label' => ScheduleConflictService::formatOfficeHourLabel($b),
                        'a_section_id' => null,
                        'b_section_id' => null,
                    ];
                }
            }

            // class vs office
            foreach ($classList as $c) {
                foreach ($officeList as $o) {
                    if (!ScheduleConflictService::dayOverlap($c->days_json ?? [], $o->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($c->starts_at, $c->ends_at, $o->starts_at, $o->ends_at)) continue;
                    $conflicts[] = [
                        'instructor' => $c->section->instructor,
                        'type' => 'CLASS vs OFFICE',
                        'a_label' => ScheduleConflictService::formatMeetingBlockLabel($c),
                        'b_label' => ScheduleConflictService::formatOfficeHourLabel($o),
                        'a_section_id' => $c->section_id,
                        'b_section_id' => null,
                    ];
                }
            }
        }

        return $conflicts;
    }
}

<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleConflictService;
use Illuminate\Http\Request;

class ScheduleTermLockController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    public function lock(Request $request, ScheduleConflictService $conflicts)
    {
        $term = $this->activeTermOrFail();

        if ($term->schedule_locked) {
            return redirect()->route('aop.schedule.home')->with('status', 'Schedule is already locked for the active term.');
        }

        // Compute readiness issues for warning message (but allow locking)
        $warningParts = $this->computeReadinessWarnings($term, $conflicts);

        $term->update([
            'schedule_locked' => true,
            'schedule_locked_at' => now(),
            'schedule_locked_by_user_id' => auth()->id(),
        ]);

        $msg = 'Schedule locked for the active term.';
        if (!empty($warningParts)) {
            $msg .= ' Warning: ' . implode(' | ', $warningParts);
        }

        return redirect()->route('aop.schedule.home')->with('status', $msg);
    }

    public function unlock(Request $request)
    {
        $term = $this->activeTermOrFail();

        if (!$term->schedule_locked) {
            return redirect()->route('aop.schedule.home')->with('status', 'Schedule is already unlocked for the active term.');
        }

        $term->update([
            'schedule_locked' => false,
            'schedule_locked_at' => null,
            'schedule_locked_by_user_id' => null,
        ]);

        return redirect()->route('aop.schedule.home')->with('status', 'Schedule unlocked for the active term.');
    }

    private function computeReadinessWarnings(Term $term, ScheduleConflictService $conflicts): array
    {
        $warnings = [];

        $sections = Section::query()
            ->with(['offering', 'meetingBlocks'])
            ->whereHas('offering', fn($q) => $q->where('term_id', $term->id))
            ->get();

        $missingInstructor = $sections->whereNull('instructor_id')->count();
        if ($missingInstructor > 0) {
            $warnings[] = "$missingInstructor section(s) missing instructor";
        }

        $missingMeetingBlocks = $sections->filter(fn($s) => $s->meetingBlocks->count() === 0)->count();
        if ($missingMeetingBlocks > 0) {
            $warnings[] = "$missingMeetingBlocks section(s) missing meeting blocks";
        }

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering'])
            ->whereHas('section.offering', fn($q) => $q->where('term_id', $term->id))
            ->get();

        $missingRoom = $meetingBlocks->whereNull('room_id')->count();
        if ($missingRoom > 0) {
            $warnings[] = "$missingRoom meeting block(s) missing room";
        }

        // Simple conflict counts (for lock warning only)
        $roomConflicts = $this->computeRoomConflictPairs($term);
        if ($roomConflicts > 0) {
            $warnings[] = "$roomConflicts room conflict(s)";
        }

        $instructorConflicts = $this->computeInstructorConflictPairs($term);
        if ($instructorConflicts > 0) {
            $warnings[] = "$instructorConflicts instructor conflict(s)";
        }

        return $warnings;
    }

    private function computeRoomConflictPairs(Term $term): int
    {
        $blocks = MeetingBlock::query()
            ->whereNotNull('room_id')
            ->whereHas('section.offering', fn($q) => $q->where('term_id', $term->id))
            ->get();

        $byRoom = $blocks->groupBy('room_id');
        $pairs = 0;

        foreach ($byRoom as $roomId => $roomBlocks) {
            $list = $roomBlocks->values();
            $n = $list->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $pairs++;
                }
            }
        }

        return $pairs;
    }

    private function computeInstructorConflictPairs(Term $term): int
    {
        $meetingBlocks = MeetingBlock::query()
            ->with('section')
            ->whereHas('section.offering', fn($q) => $q->where('term_id', $term->id))
            ->whereHas('section', fn($q) => $q->whereNotNull('instructor_id'))
            ->get();

        $officeBlocks = OfficeHourBlock::query()
            ->where('term_id', $term->id)
            ->get();

        $pairs = 0;

        $byInstructor = $meetingBlocks->groupBy(fn($mb) => (int)$mb->section->instructor_id);
        $officeByInstructor = $officeBlocks->groupBy('instructor_id');

        foreach ($byInstructor as $insId => $classBlocks) {
            $classList = $classBlocks->values();
            $n = $classList->count();
            // class vs class
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $classList[$i];
                    $b = $classList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $pairs++;
                }
            }

            $officeList = ($officeByInstructor[$insId] ?? collect())->values();
            // office vs office
            $m = $officeList->count();
            for ($i = 0; $i < $m; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $a = $officeList[$i];
                    $b = $officeList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $pairs++;
                }
            }

            // class vs office
            foreach ($classList as $c) {
                foreach ($officeList as $o) {
                    if (!ScheduleConflictService::dayOverlap($c->days_json ?? [], $o->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($c->starts_at, $c->ends_at, $o->starts_at, $o->ends_at)) continue;
                    $pairs++;
                }
            }
        }

        // instructors with only office hours (no class blocks)
        foreach ($officeByInstructor as $insId => $officeList) {
            if ($byInstructor->has($insId)) continue;
            $list = $officeList->values();
            $m = $list->count();
            for ($i = 0; $i < $m; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) continue;
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at)) continue;
                    $pairs++;
                }
            }
        }

        return $pairs;
    }
}

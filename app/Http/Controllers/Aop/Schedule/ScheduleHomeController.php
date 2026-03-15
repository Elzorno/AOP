<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\OfficeHourBlock;
use App\Models\SchedulePublication;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleConflictService;

class ScheduleHomeController extends Controller
{
    public function index()
    {
        $term = Term::where('is_active', true)->first();

        $latestPublication = null;
        if ($term) {
            $latestPublication = SchedulePublication::where('term_id', $term->id)->orderByDesc('version')->first();
        }

        $summary = $term ? $this->buildTermSummary($term, $latestPublication) : null;

        return view('aop.schedule.index', [
            'term' => $term,
            'latestPublication' => $latestPublication,
            'summary' => $summary,
        ]);
    }

    private function buildTermSummary(Term $term, ?SchedulePublication $latestPublication): array
    {
        $offeringsCount = Offering::where('term_id', $term->id)->count();

        $sections = Section::query()
            ->with(['meetingBlocks', 'instructor'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.instructor'])
            ->whereHas('section.offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $officeBlocks = OfficeHourBlock::query()
            ->where('term_id', $term->id)
            ->get();

        return [
            'offerings_count' => $offeringsCount,
            'sections_count' => $sections->count(),
            'sections_missing_instructor_count' => $sections->whereNull('instructor_id')->count(),
            'sections_missing_meeting_blocks_count' => $sections->filter(fn ($s) => $s->meetingBlocks->count() === 0)->count(),
            'meeting_blocks_missing_room_count' => $meetingBlocks->whereNull('room_id')->count(),
            'room_conflict_count' => $this->countRoomConflicts($term, $meetingBlocks),
            'instructor_conflict_count' => $this->countInstructorConflicts($term, $meetingBlocks, $officeBlocks),
            'office_hours_failing_count' => $this->countOfficeHoursFailing($term, $officeBlocks),
            'latest_publication_version' => $latestPublication?->version,
            'term_status' => (string) ($term->status ?? 'draft'),
            'schedule_locked' => (bool) ($term->schedule_locked ?? false),
        ];
    }

    private function countRoomConflicts(Term $term, $meetingBlocks): int
    {
        $count = 0;
        $grouped = $meetingBlocks->whereNotNull('room_id')->groupBy('room_id');

        foreach ($grouped as $blocks) {
            $list = $blocks->values();
            $n = $list->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countInstructorConflicts(Term $term, $meetingBlocks, $officeBlocks): int
    {
        $count = 0;

        $meetingByInstructor = $meetingBlocks
            ->filter(fn ($mb) => (bool) $mb->section?->instructor_id)
            ->groupBy(fn ($mb) => (int) $mb->section->instructor_id);

        $officeByInstructor = $officeBlocks->groupBy('instructor_id');

        $allInstructorIds = collect(array_unique(array_merge(
            $meetingByInstructor->keys()->all(),
            $officeByInstructor->keys()->all(),
        )));

        foreach ($allInstructorIds as $instructorId) {
            $classList = ($meetingByInstructor[$instructorId] ?? collect())->values();
            $officeList = ($officeByInstructor[$instructorId] ?? collect())->values();

            $n = $classList->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $classList[$i];
                    $b = $classList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }

            $m = $officeList->count();
            for ($i = 0; $i < $m; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $a = $officeList[$i];
                    $b = $officeList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }

            foreach ($classList as $classBlock) {
                foreach ($officeList as $officeBlock) {
                    if (!ScheduleConflictService::dayOverlap($classBlock->days_json ?? [], $officeBlock->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($classBlock->starts_at, $classBlock->ends_at, $officeBlock->starts_at, $officeBlock->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countOfficeHoursFailing(Term $term, $officeBlocks): int
    {
        $fullTimeInstructors = Instructor::query()
            ->where('is_active', true)
            ->where('is_full_time', true)
            ->get();

        $officeByInstructor = $officeBlocks->groupBy('instructor_id');
        $failing = 0;

        foreach ($fullTimeInstructors as $instructor) {
            $blocks = ($officeByInstructor[$instructor->id] ?? collect())->values();
            $minutesPerWeek = 0;
            $days = [];

            foreach ($blocks as $block) {
                $blockDays = ScheduleConflictService::normalizeDays($block->days_json ?? []);
                $days = array_merge($days, $blockDays);
                $minutesPerWeek += $this->durationMinutes((string) $block->starts_at, (string) $block->ends_at) * count($blockDays);
            }

            $meetsHours = $minutesPerWeek >= 240;
            $meetsDays = count(array_unique($days)) >= 3;

            if (!($meetsHours && $meetsDays)) {
                $failing++;
            }
        }

        return $failing;
    }

    private function durationMinutes(string $startsAt, string $endsAt): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', substr($startsAt, 0, 5)));
        [$endHour, $endMinute] = array_map('intval', explode(':', substr($endsAt, 0, 5)));
        $start = ($startHour * 60) + $startMinute;
        $end = ($endHour * 60) + $endMinute;

        return max(0, $end - $start);
    }
}

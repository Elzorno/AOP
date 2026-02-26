<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Term;
use Illuminate\Support\Collection;

class ScheduleConflictService
{
    /**
     * Returns true if time ranges overlap (exclusive end).
     */
    public static function timesOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        $a0 = self::toMinutes($startA);
        $a1 = self::toMinutes($endA);
        $b0 = self::toMinutes($startB);
        $b1 = self::toMinutes($endB);

        // [a0,a1) overlaps [b0,b1)
        return ($a0 < $b1) && ($b0 < $a1);
    }

    public static function dayOverlap(array $daysA, array $daysB): bool
    {
        $setB = array_fill_keys($daysB, true);
        foreach ($daysA as $d) {
            if (isset($setB[$d])) return true;
        }
        return false;
    }

    private static function toMinutes(string $hhmm): int
    {
        $hhmm = substr($hhmm, 0, 5);
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return ($h * 60) + $m;
    }

    /**
     * MeetingBlock room conflicts: class vs class only (office hours excluded).
     */
    public function roomConflictsForMeetingBlock(Term $term, int $roomId, array $days, string $startsAt, string $endsAt, ?int $ignoreMeetingBlockId = null): Collection
    {
        if (!$roomId) return collect();

        $q = MeetingBlock::query()
            ->where('room_id', $roomId)
            ->whereHas('section.offering', function ($q) use ($term) {
                $q->where('term_id', $term->id);
            });

        if ($ignoreMeetingBlockId) {
            $q->where('id', '!=', $ignoreMeetingBlockId);
        }

        $candidates = $q->get();

        return $candidates->filter(function (MeetingBlock $mb) use ($days, $startsAt, $endsAt) {
            $mbDays = $mb->days_json ?? [];
            if (!self::dayOverlap($days, $mbDays)) return false;
            return self::timesOverlap($startsAt, $endsAt, $mb->starts_at, $mb->ends_at);
        })->values();
    }

    /**
     * Instructor conflicts for meeting blocks:
     * - class vs class
     * - class vs office hours
     */
    public function instructorConflictsForMeetingBlock(Term $term, int $instructorId, array $days, string $startsAt, string $endsAt, ?int $ignoreMeetingBlockId = null): array
    {
        $meetingConflicts = MeetingBlock::query()
            ->whereHas('section', function ($q) use ($instructorId) {
                $q->where('instructor_id', $instructorId);
            })
            ->whereHas('section.offering', function ($q) use ($term) {
                $q->where('term_id', $term->id);
            });

        if ($ignoreMeetingBlockId) {
            $meetingConflicts->where('id', '!=', $ignoreMeetingBlockId);
        }

        $meetingBlocks = $meetingConflicts->get()
            ->filter(function (MeetingBlock $mb) use ($days, $startsAt, $endsAt) {
                $mbDays = $mb->days_json ?? [];
                if (!self::dayOverlap($days, $mbDays)) return false;
                return self::timesOverlap($startsAt, $endsAt, $mb->starts_at, $mb->ends_at);
            })->values();

        $officeBlocks = OfficeHourBlock::query()
            ->where('term_id', $term->id)
            ->where('instructor_id', $instructorId)
            ->get()
            ->filter(function (OfficeHourBlock $ob) use ($days, $startsAt, $endsAt) {
                $obDays = $ob->days_json ?? [];
                if (!self::dayOverlap($days, $obDays)) return false;
                return self::timesOverlap($startsAt, $endsAt, $ob->starts_at, $ob->ends_at);
            })->values();

        return [
            'meeting_blocks' => $meetingBlocks,
            'office_hour_blocks' => $officeBlocks,
        ];
    }

    /**
     * Instructor conflicts for office hour blocks:
     * - office hours vs office hours
     * - office hours vs class meeting blocks
     */
    public function instructorConflictsForOfficeHourBlock(Term $term, int $instructorId, array $days, string $startsAt, string $endsAt, ?int $ignoreOfficeHourBlockId = null): array
    {
        $officeQ = OfficeHourBlock::query()
            ->where('term_id', $term->id)
            ->where('instructor_id', $instructorId);

        if ($ignoreOfficeHourBlockId) {
            $officeQ->where('id', '!=', $ignoreOfficeHourBlockId);
        }

        $officeBlocks = $officeQ->get()
            ->filter(function (OfficeHourBlock $ob) use ($days, $startsAt, $endsAt) {
                $obDays = $ob->days_json ?? [];
                if (!self::dayOverlap($days, $obDays)) return false;
                return self::timesOverlap($startsAt, $endsAt, $ob->starts_at, $ob->ends_at);
            })->values();

        $meetingBlocks = MeetingBlock::query()
            ->whereHas('section', function ($q) use ($instructorId) {
                $q->where('instructor_id', $instructorId);
            })
            ->whereHas('section.offering', function ($q) use ($term) {
                $q->where('term_id', $term->id);
            })
            ->get()
            ->filter(function (MeetingBlock $mb) use ($days, $startsAt, $endsAt) {
                $mbDays = $mb->days_json ?? [];
                if (!self::dayOverlap($days, $mbDays)) return false;
                return self::timesOverlap($startsAt, $endsAt, $mb->starts_at, $mb->ends_at);
            })->values();

        return [
            'office_hour_blocks' => $officeBlocks,
            'meeting_blocks' => $meetingBlocks,
        ];
    }

    public static function formatMeetingBlockLabel(MeetingBlock $mb): string
    {
        $mb->loadMissing('section.offering.catalogCourse', 'room');
        $course = $mb->section->offering->catalogCourse->code ?? 'COURSE';
        $sec = $mb->section->section_code ?? 'SEC';
        $days = implode(',', $mb->days_json ?? []);
        $time = substr($mb->starts_at, 0, 5) . '-' . substr($mb->ends_at, 0, 5);
        $room = $mb->room?->name ?? '—';
        return "$course $sec ($days $time, Room: $room)";
    }

    public static function formatOfficeHourLabel(OfficeHourBlock $ob): string
    {
        $days = implode(',', $ob->days_json ?? []);
        $time = substr($ob->starts_at, 0, 5) . '-' . substr($ob->ends_at, 0, 5);
        return "Office Hours ($days $time)";
    }
}

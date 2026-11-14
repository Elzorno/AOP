<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use App\Models\Term;
use Illuminate\Support\Collection;

class ScheduleConflictService
{
    public function __construct(private readonly ScheduleEventQueryService $events)
    {
    }

    /**
     * Returns true if time ranges overlap (exclusive end).
     *
     * If $bufferMinutes > 0, each range is extended forward by that buffer
     * before testing overlap. This enforces a minimum gap between blocks while
     * still allowing an exact gap equal to the configured buffer.
     *
     * Example with a 10-minute buffer:
     * - 11:50 → 12:00 gap: allowed
     * - 11:51 → 12:00 gap: conflict
     */
    public static function timesOverlap(string $startA, string $endA, string $startB, string $endB, int $bufferMinutes = 0): bool
    {
        $a0 = self::toMinutes($startA);
        $a1 = self::toMinutes($endA);
        $b0 = self::toMinutes($startB);
        $b1 = self::toMinutes($endB);

        $buf = max(0, (int) $bufferMinutes);

        // [a0, a1+buf) overlaps [b0, b1+buf)
        return ($a0 < ($b1 + $buf)) && ($b0 < ($a1 + $buf));
    }

    public static function dayOverlap(array $daysA, array $daysB): bool
    {
        $daysA = self::normalizeDays($daysA);
        $daysB = self::normalizeDays($daysB);
        $setB = array_fill_keys($daysB, true);
        foreach ($daysA as $d) {
            if (isset($setB[$d])) return true;
        }
        return false;
    }

    /**
     * Accepts either an array of day tokens or legacy JSON-encoded strings.
     */
    public static function normalizeDays($days): array
    {
        if (is_string($days)) {
            $decoded = json_decode($days, true);
            if (is_array($decoded)) {
                $days = $decoded;
            } else {
                $days = array_filter(array_map('trim', explode(',', $days)));
            }
        }

        if (!is_array($days)) return [];

        $out = [];
        foreach ($days as $d) {
            if (!is_string($d)) continue;
            $d = trim($d);
            if ($d === '') continue;
            $out[] = $d;
        }

        return array_values(array_unique($out));
    }

    public static function durationMinutes(string $start, string $end): int
    {
        return max(0, self::toMinutes($end) - self::toMinutes($start));
    }

    /**
     * Calculates recurring weekly minutes for a day-pattern block.
     *
     * Accepts either an array of day tokens or a legacy JSON/csv string.
     */
    public static function weeklyMinutes($days, string $start, string $end): int
    {
        return self::durationMinutes($start, $end) * count(self::normalizeDays($days));
    }

    private static function toMinutes(string $hhmm): int
    {
        $hhmm = substr($hhmm, 0, 5);
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return ($h * 60) + $m;
    }

    /**
     * MeetingBlock room conflicts:
     * - class vs class
     * - class vs open labs
     */
    public function roomConflictsForMeetingBlock(Term $term, int $roomId, array $days, string $startsAt, string $endsAt, ?int $ignoreMeetingBlockId = null): array
    {
        if (!$roomId) {
            return [
                'meeting_blocks' => collect(),
                'open_labs' => collect(),
            ];
        }

        $events = $this->events->roomConflicts($term, $roomId, $days, $startsAt, $endsAt);

        return $this->legacyRoomConflictModels($this->withoutSource($events, MeetingBlock::class, $ignoreMeetingBlockId));
    }

    /**
     * Open-lab room conflicts:
     * - open lab vs class
     * - open lab vs open lab
     */
    public function roomConflictsForOpenLab(Term $term, int $roomId, array $days, string $startsAt, string $endsAt, ?int $ignoreOpenLabId = null): array
    {
        if (!$roomId) {
            return [
                'meeting_blocks' => collect(),
                'open_labs' => collect(),
            ];
        }

        $events = $this->events->roomConflicts($term, $roomId, $days, $startsAt, $endsAt);

        return $this->legacyRoomConflictModels($this->withoutSource($events, OpenLab::class, $ignoreOpenLabId));
    }

    public function labTechConflictsForOpenLab(Term $term, int $labTechId, array $days, string $startsAt, string $endsAt, ?int $ignoreOpenLabId = null): Collection
    {
        $events = $this->events->staffConflicts($term, 'lab_tech', $labTechId, $days, $startsAt, $endsAt);
        $ids = $this->withoutSource($events, OpenLab::class, $ignoreOpenLabId)
            ->where('source_type', OpenLab::class)
            ->pluck('source_id');

        return OpenLab::query()->with(['room', 'labTech'])->whereKey($ids)->get();
    }

    /**
     * Instructor conflicts for meeting blocks:
     * - class vs class
     * - class vs office hours
     */
    public function instructorConflictsForMeetingBlock(Term $term, int $instructorId, array $days, string $startsAt, string $endsAt, ?int $ignoreMeetingBlockId = null): array
    {
        $buffer = (int)($term->buffer_minutes ?? 0);
        $staffEvents = $this->withoutSource(
            $this->events->staffConflicts($term, 'instructor', $instructorId, $days, $startsAt, $endsAt),
            MeetingBlock::class,
            $ignoreMeetingBlockId,
        );
        $meetingEventIds = $staffEvents->where('source_type', MeetingBlock::class)->pluck('source_id');

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'room'])
            ->whereKey($meetingEventIds)
            ->get();

        $officeBlocks = OfficeHourBlock::query()
            ->whereKey($staffEvents->where('source_type', OfficeHourBlock::class)->pluck('source_id'))
            ->get();

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
        $buffer = (int)($term->buffer_minutes ?? 0);

        $staffEvents = $this->withoutSource(
            $this->events->staffConflicts($term, 'instructor', $instructorId, $days, $startsAt, $endsAt),
            OfficeHourBlock::class,
            $ignoreOfficeHourBlockId,
        );
        $officeBlocks = OfficeHourBlock::query()
            ->whereKey($staffEvents->where('source_type', OfficeHourBlock::class)->pluck('source_id'))
            ->get();
        $meetingEventIds = $staffEvents->where('source_type', MeetingBlock::class)->pluck('source_id');

        $meetingBlocks = MeetingBlock::query()->whereKey($meetingEventIds)->get();

        return [
            'office_hour_blocks' => $officeBlocks,
            'meeting_blocks' => $meetingBlocks,
        ];
    }

    private function withoutSource(Collection $events, string $sourceType, ?int $sourceId): Collection
    {
        if (!$sourceId) {
            return $events;
        }

        return $events
            ->reject(fn ($event) => $event->source_type === $sourceType && (int) $event->source_id === $sourceId)
            ->values();
    }

    private function legacyRoomConflictModels(Collection $events): array
    {
        $meetingBlockIds = $events->where('source_type', MeetingBlock::class)->pluck('source_id');
        $openLabIds = $events->where('source_type', OpenLab::class)->pluck('source_id');

        return [
            'meeting_blocks' => MeetingBlock::query()
                ->with(['section.offering.catalogCourse', 'room'])
                ->whereKey($meetingBlockIds)
                ->get(),
            'open_labs' => OpenLab::query()
                ->with(['room', 'labTech'])
                ->whereKey($openLabIds)
                ->get(),
        ];
    }

    public static function formatMeetingBlockLabel(MeetingBlock $mb): string
    {
        $mb->loadMissing('section.offering.catalogCourse', 'room');
        $course = $mb->section->offering->catalogCourse->code ?? 'COURSE';
        $sec = $mb->section->section_code ?? 'SEC';
        $days = implode(',', self::normalizeDays($mb->days_json ?? []));
        $time = substr($mb->starts_at, 0, 5) . '-' . substr($mb->ends_at, 0, 5);
        $room = $mb->room?->name ?? '—';
        return "$course $sec ($days $time, Room: $room)";
    }

    public static function formatOpenLabLabel(OpenLab $openLab): string
    {
        $openLab->loadMissing('room', 'labTech');
        $days = implode(',', self::normalizeDays($openLab->days_json ?? []));
        $time = substr((string) $openLab->starts_at, 0, 5) . '-' . substr((string) $openLab->ends_at, 0, 5);
        $room = $openLab->room?->name ?? '—';
        $labTech = $openLab->labTech?->name ?? 'Unassigned';

        return "{$openLab->name} ($days $time, Room: $room, Lab Tech: $labTech)";
    }

    public static function formatRoomConflictMessages(array $roomConflicts): array
    {
        $meetingBlockLabels = ($roomConflicts['meeting_blocks'] ?? collect())
            ->map(fn (MeetingBlock $meetingBlock) => self::formatMeetingBlockLabel($meetingBlock))
            ->all();

        $openLabLabels = ($roomConflicts['open_labs'] ?? collect())
            ->map(fn (OpenLab $openLab) => self::formatOpenLabLabel($openLab))
            ->all();

        return array_values(array_merge($meetingBlockLabels, $openLabLabels));
    }

    public static function formatOfficeHourLabel(OfficeHourBlock $ob): string
    {
        $days = implode(',', self::normalizeDays($ob->days_json ?? []));
        $time = substr($ob->starts_at, 0, 5) . '-' . substr($ob->ends_at, 0, 5);
        return "Office Hours ($days $time)";
    }
}

#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mkdir -p "$ROOT_DIR/app/Http/Controllers/Aop/Schedule"

cat > "$ROOT_DIR/app/Http/Controllers/Aop/Schedule/ScheduleGridController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Room;
use App\Models\Term;

class ScheduleGridController extends Controller
{
    private const DAYS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

    private function activeTermOrNull(): ?Term
    {
        return Term::where('is_active', true)->first();
    }

    private function activeTermOrFail(): Term
    {
        $term = $this->activeTermOrNull();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    public function index()
    {
        $term = $this->activeTermOrNull();

        return view('aop.schedule.grids.index', [
            'term' => $term,
            'instructors' => Instructor::where('is_active', true)->orderBy('name')->get(),
            'rooms' => Room::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function instructor(Instructor $instructor)
    {
        $term = $this->activeTermOrFail();
        abort_if(!$instructor->is_active, 404, 'Instructor not found.');

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereHas('section', function ($q) use ($instructor, $term) {
                $q->where('instructor_id', $instructor->id)
                  ->whereHas('offering', fn ($qq) => $qq->where('term_id', $term->id));
            })
            ->orderBy('starts_at')
            ->get();

        $officeBlocks = OfficeHourBlock::query()
            ->where('term_id', $term->id)
            ->where('instructor_id', $instructor->id)
            ->orderBy('starts_at')
            ->get();

        $events = [];

        foreach ($meetingBlocks as $mb) {
            $course = $mb->section->offering->catalogCourse;
            $roomName = $mb->room?->name ?? 'TBD';
            $typeLabel = $this->formatMeetingBlockType($mb->type);
            $label = sprintf('%s %s (%s) — %s', $course->code, $mb->section->section_code, $typeLabel, $roomName);

            $events[] = [
                'kind' => 'class',
                'days' => $mb->days_json ?? [],
                'starts_at' => $this->normalizeTime($mb->starts_at),
                'ends_at' => $this->normalizeTime($mb->ends_at),
                'label' => $label,
                'notes' => $mb->notes,
            ];
        }

        foreach ($officeBlocks as $ob) {
            $label = 'Office Hours';
            if (!empty($ob->notes)) {
                $label .= ' — ' . $ob->notes;
            }

            $events[] = [
                'kind' => 'office',
                'days' => $ob->days_json ?? [],
                'starts_at' => $this->normalizeTime($ob->starts_at),
                'ends_at' => $this->normalizeTime($ob->ends_at),
                'label' => $label,
                'notes' => null,
            ];
        }

        [$start, $end] = $this->computeWindow($events, '08:00', '18:00', 30);
        $grid = $this->buildGrid($events, self::DAYS, $start, $end, 30);

        return view('aop.schedule.grids.instructor', [
            'term' => $term,
            'instructor' => $instructor,
            'days' => self::DAYS,
            'start' => $start,
            'end' => $end,
            'slot_minutes' => 30,
            'grid' => $grid,
        ]);
    }

    public function room(Room $room)
    {
        $term = $this->activeTermOrFail();
        abort_if(!$room->is_active, 404, 'Room not found.');

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->where('room_id', $room->id)
            ->whereHas('section', fn ($q) => $q->whereHas('offering', fn ($qq) => $qq->where('term_id', $term->id)))
            ->orderBy('starts_at')
            ->get();

        $events = [];

        foreach ($meetingBlocks as $mb) {
            $course = $mb->section->offering->catalogCourse;
            $instructorName = $mb->section->instructor?->name ?? 'TBD';
            $typeLabel = $this->formatMeetingBlockType($mb->type);
            $label = sprintf('%s %s (%s) — %s', $course->code, $mb->section->section_code, $typeLabel, $instructorName);

            $events[] = [
                'kind' => 'class',
                'days' => $mb->days_json ?? [],
                'starts_at' => $this->normalizeTime($mb->starts_at),
                'ends_at' => $this->normalizeTime($mb->ends_at),
                'label' => $label,
                'notes' => $mb->notes,
            ];
        }

        [$start, $end] = $this->computeWindow($events, '08:00', '18:00', 30);
        $grid = $this->buildGrid($events, self::DAYS, $start, $end, 30);

        return view('aop.schedule.grids.room', [
            'term' => $term,
            'room' => $room,
            'days' => self::DAYS,
            'start' => $start,
            'end' => $end,
            'slot_minutes' => 30,
            'grid' => $grid,
        ]);
    }

    private function formatMeetingBlockType($type): string
    {
        if ($type instanceof MeetingBlockType) {
            return $type->value; // LECTURE/LAB/OTHER
        }

        if (is_string($type)) {
            return $type;
        }

        return 'OTHER';
    }

    /**
     * @return array{0:string,1:string}
     */
    private function computeWindow(array $events, string $defaultStart, string $defaultEnd, int $slotMinutes): array
    {
        if (count($events) === 0) {
            return [$defaultStart, $defaultEnd];
        }

        $min = null;
        $max = null;

        foreach ($events as $e) {
            $s = $e['starts_at'];
            $en = $e['ends_at'];
            $min = $min === null ? $s : min($min, $s);
            $max = $max === null ? $en : max($max, $en);
        }

        $start = $this->roundDownToSlot($min ?? $defaultStart, $slotMinutes);
        $end = $this->roundUpToSlot($max ?? $defaultEnd, $slotMinutes);

        // Enforce a minimum reasonable window
        if ($this->timeToMinutes($end) - $this->timeToMinutes($start) < $slotMinutes * 2) {
            $start = $defaultStart;
            $end = $defaultEnd;
        }

        return [$start, $end];
    }

    private function buildGrid(array $events, array $days, string $start, string $end, int $slotMinutes): array
    {
        $startMin = $this->timeToMinutes($start);
        $endMin = $this->timeToMinutes($end);
        $slots = (int)(($endMin - $startMin) / $slotMinutes);

        // Initialize
        $grid = [];
        foreach ($days as $d) {
            $grid[$d] = array_fill(0, $slots, null);
        }

        // Place events
        foreach ($events as $event) {
            $eventStart = $this->timeToMinutes($event['starts_at']);
            $eventEnd = $this->timeToMinutes($event['ends_at']);

            $startIdx = (int)(($eventStart - $startMin) / $slotMinutes);
            $endIdx = (int)(($eventEnd - $startMin) / $slotMinutes);

            // Clamp
            $startIdx = max(0, min($slots - 1, $startIdx));
            $endIdx = max(0, min($slots, $endIdx));

            $rowspan = max(1, $endIdx - $startIdx);

            foreach (($event['days'] ?? []) as $day) {
                if (!in_array($day, $days, true)) {
                    continue;
                }

                // If something is already there at the start slot, do not attempt to merge; just append in the same cell.
                if (is_array($grid[$day][$startIdx]) && ($grid[$day][$startIdx]['type'] ?? null) === 'cell') {
                    $grid[$day][$startIdx]['events'][] = $event;
                    continue;
                }

                // Mark covered slots as skip
                for ($i = $startIdx; $i < min($slots, $startIdx + $rowspan); $i++) {
                    $grid[$day][$i] = ['type' => 'skip'];
                }

                // Place the master cell
                $grid[$day][$startIdx] = [
                    'type' => 'cell',
                    'rowspan' => $rowspan,
                    'events' => [$event],
                ];
            }
        }

        return [
            'start' => $start,
            'end' => $end,
            'slot_minutes' => $slotMinutes,
            'slots' => $slots,
            'days' => $days,
            'cells' => $grid,
        ];
    }

    private function normalizeTime($time): string
    {
        // $time can be "HH:MM:SS" or "HH:MM"
        $t = (string)$time;
        return substr($t, 0, 5);
    }

    private function timeToMinutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return ($h * 60) + $m;
    }

    private function minutesToTime(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }

    private function roundDownToSlot(string $hhmm, int $slotMinutes): string
    {
        $min = $this->timeToMinutes($hhmm);
        $rounded = (int)(floor($min / $slotMinutes) * $slotMinutes);
        return $this->minutesToTime($rounded);
    }

    private function roundUpToSlot(string $hhmm, int $slotMinutes): string
    {
        $min = $this->timeToMinutes($hhmm);
        $rounded = (int)(ceil($min / $slotMinutes) * $slotMinutes);
        return $this->minutesToTime($rounded);
    }
}
PHP

# Permissions: keep readable by www-data
chown www-data:www-data "$ROOT_DIR/app/Http/Controllers/Aop/Schedule/ScheduleGridController.php"
chmod 644 "$ROOT_DIR/app/Http/Controllers/Aop/Schedule/ScheduleGridController.php"

echo "OK: Applied Phase 6.1 hotfix (enum-to-string fix for schedule grids)."

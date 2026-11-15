<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\Term;
use App\Services\ScheduleEventReadAdapter;
use Barryvdh\DomPDF\Facade\Pdf;

class ScheduleGridController extends Controller
{
    private const DAYS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

    public function __construct(private readonly ScheduleEventReadAdapter $eventReads) {}

    public function index(Term $term)
    {
        return view('aop.schedule.grids.index', [
            'term' => $term,
            'instructors' => Instructor::where('is_active', true)->orderBy('name')->get(),
            'rooms' => Room::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function instructor(Term $term, Instructor $instructor)
    {
        return view('aop.schedule.grids.instructor', [
            ...$this->buildInstructorGridViewData($term, $instructor),
            'isPrint' => (bool)request()->boolean('print'),
            'autoPrint' => true,
        ]);
    }

    public function instructorPdf(Term $term, Instructor $instructor)
    {
        $viewData = [
            ...$this->buildInstructorGridViewData($term, $instructor),
            'isPrint' => true,
            'isPdf' => true,
            'autoPrint' => false,
        ];

        $filename = sprintf('instructor-grid-%s-%s.pdf', $viewData['term']->code, $this->safeFilename($instructor->name));

        return Pdf::loadView('aop.schedule.grids.instructor', $viewData)
            ->setPaper('letter', 'portrait')
            ->download($filename);
    }

    public function room(Term $term, Room $room)
    {
        return view('aop.schedule.grids.room', [
            ...$this->buildRoomGridViewData($term, $room),
            'isPrint' => (bool)request()->boolean('print'),
            'autoPrint' => true,
        ]);
    }

    public function roomPdf(Term $term, Room $room)
    {
        $viewData = [
            ...$this->buildRoomGridViewData($term, $room),
            'isPrint' => true,
            'isPdf' => true,
            'autoPrint' => false,
        ];

        $filename = sprintf('room-grid-%s-%s.pdf', $viewData['term']->code, $this->safeFilename($room->name));

        return Pdf::loadView('aop.schedule.grids.room', $viewData)
            ->setPaper('letter', 'portrait')
            ->download($filename);
    }

    private function buildInstructorGridViewData(Term $term, Instructor $instructor): array
    {
        abort_if(!$instructor->is_active, 404, 'Instructor not found.');

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereHas('scheduledEvent', fn ($events) => $events
                ->where('term_id', $term->id)
                ->whereHas('staffAssignments', fn ($staff) => $staff->where('instructor_id', $instructor->id)))
            ->orderBy('starts_at')
            ->get();
        $this->eventReads->meetingBlocks($meetingBlocks);

        $officeBlocks = OfficeHourBlock::query()
            ->whereHas('scheduledEvent', fn ($events) => $events
                ->where('term_id', $term->id)
                ->whereHas('staffAssignments', fn ($staff) => $staff->where('instructor_id', $instructor->id)))
            ->orderBy('starts_at')
            ->get();
        $this->eventReads->officeHours($officeBlocks);

        $events = [];
        $classStyle = $this->buildClassEventStyle($instructor->color_hex_css);

        foreach ($meetingBlocks as $mb) {
            $course = $mb->section->offering->catalogCourse;
            $roomName = $mb->room?->name ?? 'TBD';
            $courseCode = trim(sprintf('%s-%s', $course->code, $mb->section->section_code), '-');
            $courseTitle = $course->title ?? '';

            $events[] = [
                'kind' => 'class',
                'days' => $mb->days_json ?? [],
                'starts_at' => $this->normalizeTime($mb->starts_at),
                'ends_at' => $this->normalizeTime($mb->ends_at),
                'label' => $courseCode,
                'course' => $courseCode,
                'title' => $courseTitle,
                'meta' => $roomName,
                'notes' => $mb->notes,
                'style' => $classStyle,
            ];
        }

        foreach ($officeBlocks as $ob) {
            $label = 'Office Hours';
            if (!empty($ob->notes)) {
                $label .= ' - ' . $ob->notes;
            }

            $events[] = [
                'kind' => 'office',
                'days' => $ob->days_json ?? [],
                'starts_at' => $this->normalizeTime($ob->starts_at),
                'ends_at' => $this->normalizeTime($ob->ends_at),
                'label' => $label,
                'course' => 'Office Hours',
                'title' => !empty($ob->notes) ? $ob->notes : 'Instructor Availability',
                'meta' => null,
                'notes' => null,
                'style' => [],
            ];
        }

        [$start, $end] = $this->computeWindow($events, '08:00', '18:00', 30);
        $grid = $this->buildGrid($events, self::DAYS, $start, $end, 30);

        return [
            'term' => $term,
            'instructor' => $instructor,
            'days' => self::DAYS,
            'start' => $start,
            'end' => $end,
            'slot_minutes' => 30,
            'grid' => $grid,
        ];
    }

    private function buildRoomGridViewData(Term $term, Room $room): array
    {
        abort_if(!$room->is_active, 404, 'Room not found.');

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereHas('scheduledEvent', fn ($events) => $events->where('term_id', $term->id)->where('room_id', $room->id))
            ->orderBy('starts_at')
            ->get();
        $this->eventReads->meetingBlocks($meetingBlocks);

        $openLabs = OpenLab::query()
            ->with(['room', 'labTech'])
            ->whereHas('scheduledEvent', fn ($events) => $events->where('term_id', $term->id)->where('room_id', $room->id))
            ->orderBy('starts_at')
            ->get();
        $this->eventReads->openLabs($openLabs);

        $events = [];

        foreach ($meetingBlocks as $mb) {
            $course = $mb->section->offering->catalogCourse;
            $instructor = $mb->section->instructor;
            $instructorName = $mb->section->instructor_display_name ?? 'TBD';
            $courseCode = trim(sprintf('%s-%s', $course->code, $mb->section->section_code), '-');
            $courseTitle = $course->title ?? '';

            $events[] = [
                'kind' => 'class',
                'days' => $mb->days_json ?? [],
                'starts_at' => $this->normalizeTime($mb->starts_at),
                'ends_at' => $this->normalizeTime($mb->ends_at),
                'label' => $courseCode,
                'course' => $courseCode,
                'title' => $courseTitle,
                'meta' => $instructorName,
                'notes' => $mb->notes,
                'style' => $this->buildClassEventStyle($instructor?->color_hex_css),
            ];
        }

        foreach ($openLabs as $openLab) {
            $events[] = [
                'kind' => 'open-lab',
                'days' => $openLab->days_json ?? [],
                'starts_at' => $this->normalizeTime($openLab->starts_at),
                'ends_at' => $this->normalizeTime($openLab->ends_at),
                'label' => 'Open Lab',
                'course' => 'Open Lab',
                'title' => $openLab->name,
                'meta' => $openLab->labTech?->name ?? 'Lab Tech TBD',
                'notes' => $openLab->notes,
                'style' => [
                    'bg' => 'rgba(217, 119, 6, 0.16)',
                    'border' => 'rgba(180, 83, 9, 0.42)',
                ],
            ];
        }

        [$start, $end] = $this->computeWindow($events, '08:00', '18:00', 30);
        $grid = $this->buildGrid($events, self::DAYS, $start, $end, 30);

        return [
            'term' => $term,
            'room' => $room,
            'days' => self::DAYS,
            'start' => $start,
            'end' => $end,
            'slot_minutes' => 30,
            'grid' => $grid,
        ];
    }

    private function safeFilename(string $value): string
    {
        $sanitized = preg_replace('/[^a-z0-9]+/i', '-', trim($value)) ?? 'grid';
        $sanitized = trim($sanitized, '-');

        return $sanitized !== '' ? strtolower($sanitized) : 'grid';
    }

    private function formatMeetingBlockType($type): string
    {
        if ($type instanceof MeetingBlockType) {
            return $type->value;
        }
        if (is_string($type)) {
            return $type;
        }
        return 'OTHER';
    }

    /**
     * @return array{accent?:string,bg?:string,border?:string}
     */
    private function buildClassEventStyle(?string $rawColor): array
    {
        $hex = Instructor::normalizeColorHex($rawColor);

        if (!$hex) {
            return [];
        }

        $rgb = $this->hexToRgb($hex);
        if (!$rgb) {
            return [];
        }

        [$r, $g, $b] = $rgb;

        return [
            'accent' => $hex,
            'bg' => sprintf('rgba(%d, %d, %d, 0.14)', $r, $g, $b),
            'border' => sprintf('rgba(%d, %d, %d, 0.42)', $r, $g, $b),
        ];
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private function hexToRgb(string $hex): ?array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = preg_replace('/(.)/', '$1$1', $hex);
        }

        if (strlen($hex) === 8) {
            $hex = substr($hex, 0, 6);
        }

        if (strlen($hex) !== 6) {
            return null;
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
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

        $grid = [];
        foreach ($days as $d) {
            $grid[$d] = array_fill(0, $slots, null);
        }

        foreach ($events as $event) {
            $eventStart = $this->timeToMinutes($event['starts_at']);
            $eventEnd = $this->timeToMinutes($event['ends_at']);

            $startIdx = (int) floor(($eventStart - $startMin) / $slotMinutes);
            $endIdx = (int) ceil(($eventEnd - $startMin) / $slotMinutes);

            $startIdx = max(0, min($slots - 1, $startIdx));
            $endIdx = max($startIdx + 1, min($slots, $endIdx));

            $rowspan = max(1, $endIdx - $startIdx);

            foreach (($event['days'] ?? []) as $day) {
                if (!in_array($day, $days, true)) {
                    continue;
                }

                if (is_array($grid[$day][$startIdx]) && ($grid[$day][$startIdx]['type'] ?? null) === 'cell') {
                    $grid[$day][$startIdx]['events'][] = $event;
                    continue;
                }

                for ($i = $startIdx; $i < min($slots, $startIdx + $rowspan); $i++) {
                    $grid[$day][$i] = ['type' => 'skip'];
                }

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

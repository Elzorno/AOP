<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use App\Services\ScheduleEventReadAdapter;
use App\Support\SafeCsv;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScheduleReportsController extends Controller
{
    private const DAYS_ORDER = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

    public function __construct(private readonly ScheduleEventReadAdapter $eventReads) {}

    public function index(Term $term)
    {
        $instructors = Instructor::where('is_active', true)->orderBy('name')->get();
        $rooms = Room::where('is_active', true)->orderBy('name')->get();

        $sections = Section::query()
            ->with(['offering.catalogCourse', 'instructor'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $sectionIds = $sections->pluck('id')->all();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereIn('section_id', $sectionIds)
            ->get();
        $this->eventReads->meetingBlocks($meetingBlocks);

        $openLabs = OpenLab::query()
            ->with(['room', 'labTech'])
            ->where('term_id', $term->id)
            ->get();
        $this->eventReads->openLabs($openLabs);

        $meetingBlocksBySection = $meetingBlocks->groupBy('section_id');

        $sectionsMissingInstructor = $sections->filter(fn (Section $section) => !$section->hasInstructorAssignment());
        $sectionsMissingMeetingBlocks = $sections->filter(
            fn (Section $section) => $section->requiresMeetingBlocks()
                && ($meetingBlocksBySection[$section->id] ?? collect())->isEmpty()
        );

        $meetingBlocksMissingRoom = $meetingBlocks->filter(fn ($mb) => !$mb->room_id);

        $stats = [
            'offerings' => $sections->pluck('offering_id')->unique()->count(),
            'sections' => $sections->count(),
            'meeting_blocks' => $meetingBlocks->count(),
            'office_hours_blocks' => OfficeHourBlock::where('term_id', $term->id)->count(),
            'open_labs' => $openLabs->count(),
            'lab_techs_over_limit' => app(\App\Services\LabTechWorkloadService::class)
                ->summariesForTerm($term)
                ->filter(fn (array $row) => $row['is_over_limit'])
                ->count(),
            'modalities' => $sections->groupBy('modality')->map->count()->toArray(),
            'meeting_types' => $meetingBlocks->groupBy(function ($mb) {
                return $this->meetingTypeLabel($mb->type);
            })->map->count()->toArray(),
        ];

        $unassigned = [
            'sections_missing_instructor' => $sectionsMissingInstructor,
            'sections_missing_meeting_blocks' => $sectionsMissingMeetingBlocks,
            'meeting_blocks_missing_room' => $meetingBlocksMissingRoom,
        ];

        return view('aop.schedule.reports.index', [
            'term' => $term,
            'instructors' => $instructors,
            'rooms' => $rooms,
            'stats' => $stats,
            'unassigned' => $unassigned,
            'effectiveEndDate' => $this->effectiveTermEndDate($term),
            'endDateIsCalculated' => !$term->ends_on && (bool) $term->starts_on,
        ]);
    }

    public function exportRegistrar(Term $term): StreamedResponse
    {
        $sections = Section::query()
            ->with(['offering.catalogCourse', 'instructor', 'meetingBlocks.room'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
            ->get()
            ->sortBy(fn (Section $section) => sprintf(
                '%s|%s|%08d',
                mb_strtolower((string) ($section->offering?->catalogCourse?->code ?? '')),
                mb_strtolower((string) $section->section_code),
                $section->id,
            ))
            ->values();
        $sections->each(fn (Section $section) => $this->eventReads->meetingBlocks($section->meetingBlocks));

        $filename = sprintf('aop_%s_registrar_schedule.csv', $this->safeSlug($term->code));

        return $this->streamCsv($filename, function ($out) use ($term, $sections) {
            SafeCsv::write($out, $this->registrarHeaders());

            foreach ($sections as $section) {
                $meetingBlocks = $section->meetingBlocks
                    ->sortBy(fn (MeetingBlock $meetingBlock) => sprintf(
                        '%s|%s|%s',
                        implode(',', $meetingBlock->days_json ?? []),
                        (string) $meetingBlock->starts_at,
                        (string) $meetingBlock->ends_at,
                    ))
                    ->values();

                if ($meetingBlocks->isEmpty()) {
                    SafeCsv::write($out, $this->registrarRow($term, $section));
                    continue;
                }

                foreach ($meetingBlocks as $meetingBlock) {
                    SafeCsv::write($out, $this->registrarRow($term, $section, $meetingBlock));
                }
            }
        });
    }

    public function exportTerm(Term $term): StreamedResponse
    {
        return $this->exportRegistrar($term);
    }

    public function exportInstructor(Term $term, Instructor $instructor): StreamedResponse
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

        $filename = sprintf('aop_%s_instructor_%s.csv', $term->code, $this->safeSlug($instructor->name));

        return $this->streamCsv($filename, function ($out) use ($term, $instructor, $meetingBlocks, $officeBlocks) {
            SafeCsv::write($out, [
                'Term', 'Instructor', 'Event Kind', 'Course Code', 'Section Code', 'Meeting Type', 'Days', 'Start', 'End', 'Room', 'Notes'
            ]);

            foreach ($meetingBlocks as $mb) {
                $course = $mb->section->offering->catalogCourse;
                SafeCsv::write($out, [
                    $term->code,
                    $instructor->name,
                    'CLASS',
                    $course->code,
                    $mb->section->section_code,
                    $this->meetingTypeLabel($mb->type),
                    $this->daysToString($mb->days_json ?? []),
                    $this->time5($mb->starts_at),
                    $this->time5($mb->ends_at),
                    $mb->room?->name ?? '',
                    $mb->notes ?? '',
                ]);
            }

            foreach ($officeBlocks as $ob) {
                SafeCsv::write($out, [
                    $term->code,
                    $instructor->name,
                    'OFFICE_HOURS',
                    '',
                    '',
                    'OFFICE',
                    $this->daysToString($ob->days_json ?? []),
                    $this->time5($ob->starts_at),
                    $this->time5($ob->ends_at),
                    '',
                    $ob->notes ?? '',
                ]);
            }
        });
    }

    public function exportRoom(Term $term, Room $room): StreamedResponse
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

        $filename = sprintf('aop_%s_room_%s.csv', $term->code, $this->safeSlug($room->name));

        return $this->streamCsv($filename, function ($out) use ($term, $room, $meetingBlocks, $openLabs) {
            SafeCsv::write($out, [
                'Term', 'Room', 'Event Kind', 'Display Name', 'Course Code', 'Section Code', 'Assigned Staff', 'Meeting Type', 'Days', 'Start', 'End', 'Notes'
            ]);

            foreach ($meetingBlocks as $mb) {
                $course = $mb->section->offering->catalogCourse;
                SafeCsv::write($out, [
                    $term->code,
                    $room->name,
                    'CLASS',
                    $course->title ?? '',
                    $course->code,
                    $mb->section->section_code,
                    $mb->section->instructor_display_name ?? '',
                    $this->meetingTypeLabel($mb->type),
                    $this->daysToString($mb->days_json ?? []),
                    $this->time5($mb->starts_at),
                    $this->time5($mb->ends_at),
                    $mb->notes ?? '',
                ]);
            }

            foreach ($openLabs as $openLab) {
                SafeCsv::write($out, [
                    $term->code,
                    $room->name,
                    'OPEN_LAB',
                    $openLab->name,
                    '',
                    '',
                    $openLab->labTech?->name ?? '',
                    'OPEN_LAB',
                    $this->daysToString($openLab->days_json ?? []),
                    $this->time5($openLab->starts_at),
                    $this->time5($openLab->ends_at),
                    $openLab->notes ?? '',
                ]);
            }
        });
    }

    private function registrarHeaders(): array
    {
        return [
            'Term',
            'Course Code',
            'Section',
            'Course-Section',
            'Title',
            'Faculty',
            'Credits',
            'Capacity',
            'Enrolled',
            'Seats Open',
            'Status',
            'Modality',
            'Meeting Type',
            'Days',
            'Start Time',
            'End Time',
            'Start Date',
            'End Date',
            'Campus',
            'Building',
            'Room',
            'Registrar Schedule',
            'Notes',
        ];
    }

    private function registrarRow(Term $term, Section $section, ?MeetingBlock $meetingBlock = null): array
    {
        $course = $section->offering?->catalogCourse;
        $courseCode = trim((string) ($course?->code ?? ''));
        $sectionCode = trim((string) $section->section_code);
        $isOnline = $section->modality?->value === 'ONLINE';
        $room = $isOnline ? null : $meetingBlock?->room;

        $campus = 'Main Campus';
        $building = $isOnline ? 'Online Instruction' : trim((string) ($room?->building ?? ''));
        $roomNumber = $isOnline ? 'ONLIN' : trim((string) ($room?->room_number ?? ''));

        if (!$isOnline && $room && $building === '' && $roomNumber === '') {
            $roomNumber = $room->name;
        }

        $location = $this->registrarLocation($campus, $building, $roomNumber);
        $days = $meetingBlock ? $this->registrarDays($meetingBlock->days_json ?? []) : '';
        $startTime = $meetingBlock ? $this->registrarTime($meetingBlock->starts_at) : '';
        $endTime = $meetingBlock ? $this->registrarTime($meetingBlock->ends_at) : '';
        $startDate = $term->starts_on?->format('n/j/Y') ?? '';
        $endDate = $this->effectiveTermEndDate($term)?->format('n/j/Y') ?? '';
        $dateRange = $startDate && $endDate ? $startDate.' - '.$endDate : ($startDate ?: $endDate);

        $scheduleLines = array_values(array_filter([
            $days,
            $meetingBlock ? $this->registrarTimeRange($meetingBlock->starts_at, $meetingBlock->ends_at) : '',
            trim($dateRange.' '.$location),
        ], fn (string $value) => $value !== ''));

        $capacity = $section->section_capacity;
        $enrolled = $section->enrolled_count;
        $seatsOpen = $capacity === null
            ? ''
            : max(0, (int) $capacity - (int) ($enrolled ?? 0)).'/'.(int) $capacity;

        $notes = collect([$section->notes, $meetingBlock?->notes])
            ->filter(fn ($note) => filled($note))
            ->unique()
            ->implode(' | ');

        return [
            $term->code,
            $courseCode,
            $sectionCode,
            trim($courseCode.'-'.$sectionCode, '-'),
            $course?->title ?? '',
            $section->instructor_display_name ?? '',
            $course?->credits !== null ? number_format((float) $course->credits, 2, '.', '') : ($course?->credits_text ?? ''),
            $capacity ?? '',
            $enrolled ?? '',
            $seatsOpen,
            'Open',
            str_replace('_', ' ', $section->modality?->value ?? ''),
            $meetingBlock ? $this->meetingTypeLabel($meetingBlock->type) : '',
            $days,
            $startTime,
            $endTime,
            $startDate,
            $endDate,
            $campus,
            $building,
            $roomNumber,
            implode("\n", $scheduleLines),
            $notes,
        ];
    }

    private function registrarLocation(string $campus, string $building, string $room): string
    {
        if ($building === '' && $room === '') {
            return '';
        }

        $place = $building;
        if ($room !== '') {
            $place .= ($place !== '' ? ' - ' : '').$room;
        }

        return trim($campus.' '.$place);
    }

    private function registrarDays(array $days): string
    {
        $days = array_values(array_filter($days, fn ($day) => is_string($day) && $day !== ''));
        $order = array_flip(self::DAYS_ORDER);
        usort($days, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return implode(', ', $days);
    }

    private function registrarTime($time): string
    {
        $value = substr((string) $time, 0, 5);
        if (!preg_match('/^(\d{2}):(\d{2})$/', $value, $parts)) {
            return '';
        }

        $hour24 = (int) $parts[1];
        $hour12 = $hour24 % 12 ?: 12;

        return sprintf('%d:%s %s', $hour12, $parts[2], $hour24 >= 12 ? 'PM' : 'AM');
    }

    private function registrarTimeRange($startsAt, $endsAt): string
    {
        $start = $this->registrarTime($startsAt);
        $end = $this->registrarTime($endsAt);
        if ($start === '' || $end === '') {
            return '';
        }

        $startSuffix = substr($start, -2);
        $endSuffix = substr($end, -2);
        if ($startSuffix === $endSuffix) {
            $start = substr($start, 0, -3);
        }

        return $start.'-'.$end;
    }

    private function effectiveTermEndDate(Term $term): ?CarbonInterface
    {
        if ($term->ends_on) {
            return $term->ends_on;
        }

        if (!$term->starts_on) {
            return null;
        }

        $end = $term->starts_on->copy()->addWeeks(max(1, (int) $term->weeks_in_term) - 1);
        $daysUntilFriday = (CarbonInterface::FRIDAY - $end->dayOfWeek + 7) % 7;

        return $end->addDays($daysUntilFriday);
    }

    private function meetingTypeLabel($type): string
    {
        if ($type instanceof MeetingBlockType) {
            return $type->value;
        }
        if (is_string($type)) {
            return $type;
        }
        return 'OTHER';
    }

    private function daysToString(array $days): string
    {
        $days = array_values(array_filter($days, fn ($d) => is_string($d) && $d !== ''));
        $order = array_flip(self::DAYS_ORDER);
        usort($days, fn($a,$b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        return implode('/', $days);
    }

    private function time5($time): string
    {
        return substr((string)$time, 0, 5);
    }

    private function safeSlug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;
        return trim($s, '_');
    }

    private function streamCsv(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $writer($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

write_file() {
  local rel="$1"
  mkdir -p "$(dirname "$ROOT_DIR/$rel")"
  cat > "$ROOT_DIR/$rel"
}

write_file "app/Http/Controllers/Aop/Schedule/ScheduleGridController.php" <<'EOF_app_Http_Controllers_Aop_Schedule_ScheduleGridController_php'
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
            'isPrint' => (bool)request()->boolean('print'),
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
            'isPrint' => (bool)request()->boolean('print'),
        ]);
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

            $startIdx = (int)(($eventStart - $startMin) / $slotMinutes);
            $endIdx = (int)(($eventEnd - $startMin) / $slotMinutes);

            $startIdx = max(0, min($slots - 1, $startIdx));
            $endIdx = max(0, min($slots, $endIdx));

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
EOF_app_Http_Controllers_Aop_Schedule_ScheduleGridController_php

write_file "app/Http/Controllers/Aop/Schedule/ScheduleReportsController.php" <<'EOF_app_Http_Controllers_Aop_Schedule_ScheduleReportsController_php'
<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Http\Response;

class ScheduleReportsController extends Controller
{
    private const DAYS_ORDER = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');
        return $term;
    }

    public function index()
    {
        $term = Term::where('is_active', true)->first();

        $instructors = Instructor::where('is_active', true)->orderBy('name')->get();
        $rooms = Room::where('is_active', true)->orderBy('name')->get();

        if (!$term) {
            return view('aop.schedule.reports.index', [
                'term' => null,
                'instructors' => $instructors,
                'rooms' => $rooms,
                'stats' => null,
                'unassigned' => null,
            ]);
        }

        $sections = Section::query()
            ->with(['offering.catalogCourse', 'instructor'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $sectionIds = $sections->pluck('id')->all();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereIn('section_id', $sectionIds)
            ->get();

        $meetingBlocksBySection = $meetingBlocks->groupBy('section_id');

        $sectionsMissingInstructor = $sections->filter(fn ($s) => !$s->instructor_id);
        $sectionsMissingMeetingBlocks = $sections->filter(fn ($s) => ($meetingBlocksBySection[$s->id] ?? collect())->count() === 0);

        $meetingBlocksMissingRoom = $meetingBlocks->filter(fn ($mb) => !$mb->room_id);

        $stats = [
            'offerings' => $sections->pluck('offering_id')->unique()->count(),
            'sections' => $sections->count(),
            'meeting_blocks' => $meetingBlocks->count(),
            'office_hours_blocks' => OfficeHourBlock::where('term_id', $term->id)->count(),
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
        ]);
    }

    public function exportTerm(): Response
    {
        $term = $this->activeTermOrFail();

        $sections = Section::query()
            ->with(['offering.catalogCourse', 'instructor'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $sectionIds = $sections->pluck('id')->all();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereIn('section_id', $sectionIds)
            ->orderBy('starts_at')
            ->get();

        $filename = sprintf('aop_%s_term_schedule.csv', $term->code);

        return $this->streamCsv($filename, function ($out) use ($term, $meetingBlocks) {
            fputcsv($out, [
                'Term', 'Course Code', 'Section Code', 'Instructor', 'Room', 'Meeting Type', 'Days', 'Start', 'End', 'Notes'
            ]);

            foreach ($meetingBlocks as $mb) {
                $course = $mb->section->offering->catalogCourse;
                fputcsv($out, [
                    $term->code,
                    $course->code,
                    $mb->section->section_code,
                    $mb->section->instructor?->name ?? '',
                    $mb->room?->name ?? '',
                    $this->meetingTypeLabel($mb->type),
                    $this->daysToString($mb->days_json ?? []),
                    $this->time5($mb->starts_at),
                    $this->time5($mb->ends_at),
                    $mb->notes ?? '',
                ]);
            }
        });
    }

    public function exportInstructor(Instructor $instructor): Response
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

        $filename = sprintf('aop_%s_instructor_%s.csv', $term->code, $this->safeSlug($instructor->name));

        return $this->streamCsv($filename, function ($out) use ($term, $instructor, $meetingBlocks, $officeBlocks) {
            fputcsv($out, [
                'Term', 'Instructor', 'Event Kind', 'Course Code', 'Section Code', 'Meeting Type', 'Days', 'Start', 'End', 'Room', 'Notes'
            ]);

            foreach ($meetingBlocks as $mb) {
                $course = $mb->section->offering->catalogCourse;
                fputcsv($out, [
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
                fputcsv($out, [
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

    public function exportRoom(Room $room): Response
    {
        $term = $this->activeTermOrFail();
        abort_if(!$room->is_active, 404, 'Room not found.');

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->where('room_id', $room->id)
            ->whereHas('section', fn ($q) => $q->whereHas('offering', fn ($qq) => $qq->where('term_id', $term->id)))
            ->orderBy('starts_at')
            ->get();

        $filename = sprintf('aop_%s_room_%s.csv', $term->code, $this->safeSlug($room->name));

        return $this->streamCsv($filename, function ($out) use ($term, $room, $meetingBlocks) {
            fputcsv($out, [
                'Term', 'Room', 'Course Code', 'Section Code', 'Instructor', 'Meeting Type', 'Days', 'Start', 'End', 'Notes'
            ]);

            foreach ($meetingBlocks as $mb) {
                $course = $mb->section->offering->catalogCourse;
                fputcsv($out, [
                    $term->code,
                    $room->name,
                    $course->code,
                    $mb->section->section_code,
                    $mb->section->instructor?->name ?? '',
                    $this->meetingTypeLabel($mb->type),
                    $this->daysToString($mb->days_json ?? []),
                    $this->time5($mb->starts_at),
                    $this->time5($mb->ends_at),
                    $mb->notes ?? '',
                ]);
            }
        });
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

    private function streamCsv(string $filename, callable $writer): Response
    {
        return response()->streamDownload(function () use ($writer) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM for Excel
            fwrite($out, "ï»¿");
            $writer($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
EOF_app_Http_Controllers_Aop_Schedule_ScheduleReportsController_php

write_file "routes/web.php" <<'EOF_routes_web_php'
<?php

use App\Http\Controllers\Aop\CatalogCourseController;
use App\Http\Controllers\Aop\DashboardController;
use App\Http\Controllers\Aop\InstructorController;
use App\Http\Controllers\Aop\RoomController;
use App\Http\Controllers\Aop\TermController;
use App\Http\Controllers\Aop\Schedule\ScheduleHomeController;
use App\Http\Controllers\Aop\Schedule\OfferingController;
use App\Http\Controllers\Aop\Schedule\SectionController;
use App\Http\Controllers\Aop\Schedule\MeetingBlockController;
use App\Http\Controllers\Aop\Schedule\ScheduleGridController;
use App\Http\Controllers\Aop\Schedule\ScheduleReportsController;
use App\Http\Controllers\Aop\Schedule\OfficeHoursController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('/aop')->name('aop.')->middleware(['admin'])->group(function () {
        // Terms
        Route::get('/terms', [TermController::class, 'index'])->name('terms.index');
        Route::get('/terms/create', [TermController::class, 'create'])->name('terms.create');
        Route::post('/terms', [TermController::class, 'store'])->name('terms.store');
        Route::get('/terms/{term}/edit', [TermController::class, 'edit'])->name('terms.edit');
        Route::put('/terms/{term}', [TermController::class, 'update'])->name('terms.update');
        Route::post('/terms/active', [TermController::class, 'setActive'])->name('terms.setActive');

        // Instructors
        Route::get('/instructors', [InstructorController::class, 'index'])->name('instructors.index');
        Route::get('/instructors/create', [InstructorController::class, 'create'])->name('instructors.create');
        Route::post('/instructors', [InstructorController::class, 'store'])->name('instructors.store');
        Route::get('/instructors/{instructor}/edit', [InstructorController::class, 'edit'])->name('instructors.edit');
        Route::put('/instructors/{instructor}', [InstructorController::class, 'update'])->name('instructors.update');

        // Rooms
        Route::get('/rooms', [RoomController::class, 'index'])->name('rooms.index');
        Route::get('/rooms/create', [RoomController::class, 'create'])->name('rooms.create');
        Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');
        Route::get('/rooms/{room}/edit', [RoomController::class, 'edit'])->name('rooms.edit');
        Route::put('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');

        // Catalog
        Route::get('/catalog', [CatalogCourseController::class, 'index'])->name('catalog.index');
        Route::get('/catalog/create', [CatalogCourseController::class, 'create'])->name('catalog.create');
        Route::post('/catalog', [CatalogCourseController::class, 'store'])->name('catalog.store');
        Route::get('/catalog/{catalogCourse}/edit', [CatalogCourseController::class, 'edit'])->name('catalog.edit');
        Route::put('/catalog/{catalogCourse}', [CatalogCourseController::class, 'update'])->name('catalog.update');

        // Schedule (active term)
        Route::get('/schedule', [ScheduleHomeController::class, 'index'])->name('schedule.home');

        // Offerings
        Route::get('/schedule/offerings', [OfferingController::class, 'index'])->name('schedule.offerings.index');
        Route::get('/schedule/offerings/create', [OfferingController::class, 'create'])->name('schedule.offerings.create');
        Route::post('/schedule/offerings', [OfferingController::class, 'store'])->name('schedule.offerings.store');

        // Sections
        Route::get('/schedule/sections', [SectionController::class, 'index'])->name('schedule.sections.index');
        Route::get('/schedule/sections/create', [SectionController::class, 'create'])->name('schedule.sections.create');
        Route::post('/schedule/sections', [SectionController::class, 'store'])->name('schedule.sections.store');
        Route::get('/schedule/sections/{section}/edit', [SectionController::class, 'edit'])->name('schedule.sections.edit');
        Route::put('/schedule/sections/{section}', [SectionController::class, 'update'])->name('schedule.sections.update');

        // Meeting blocks nested under section
        Route::post('/schedule/sections/{section}/meeting-blocks', [MeetingBlockController::class, 'store'])->name('schedule.meetingBlocks.store');
        Route::put('/schedule/sections/{section}/meeting-blocks/{meetingBlock}', [MeetingBlockController::class, 'update'])->name('schedule.meetingBlocks.update');

        // Office Hours (active term)
        Route::get('/schedule/office-hours', [OfficeHoursController::class, 'index'])->name('schedule.officeHours.index');
        Route::get('/schedule/office-hours/{instructor}', [OfficeHoursController::class, 'show'])->name('schedule.officeHours.show');
        Route::post('/schedule/office-hours/{instructor}/blocks', [OfficeHoursController::class, 'store'])->name('schedule.officeHours.blocks.store');
        Route::put('/schedule/office-hours/{instructor}/blocks/{officeHourBlock}', [OfficeHoursController::class, 'update'])->name('schedule.officeHours.blocks.update');
        Route::delete('/schedule/office-hours/{instructor}/blocks/{officeHourBlock}', [OfficeHoursController::class, 'destroy'])->name('schedule.officeHours.blocks.destroy');
        Route::post('/schedule/office-hours/{instructor}/lock', [OfficeHoursController::class, 'lock'])->name('schedule.officeHours.lock');
        Route::post('/schedule/office-hours/{instructor}/unlock', [OfficeHoursController::class, 'unlock'])->name('schedule.officeHours.unlock');

        // Schedule Grids (active term)
        Route::get('/schedule/grids', [ScheduleGridController::class, 'index'])->name('schedule.grids.index');
        Route::get('/schedule/grids/instructors/{instructor}', [ScheduleGridController::class, 'instructor'])->name('schedule.grids.instructor');
        Route::get('/schedule/grids/rooms/{room}', [ScheduleGridController::class, 'room'])->name('schedule.grids.room');

        // Schedule Reports (active term)
        Route::get('/schedule/reports', [ScheduleReportsController::class, 'index'])->name('schedule.reports.index');
        Route::get('/schedule/reports/export/term', [ScheduleReportsController::class, 'exportTerm'])->name('schedule.reports.exportTerm');
        Route::get('/schedule/reports/export/instructors/{instructor}', [ScheduleReportsController::class, 'exportInstructor'])->name('schedule.reports.exportInstructor');
        Route::get('/schedule/reports/export/rooms/{room}', [ScheduleReportsController::class, 'exportRoom'])->name('schedule.reports.exportRoom');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
EOF_routes_web_php

write_file "resources/views/aop/schedule/index.blade.php" <<'EOF_resources_views_aop_schedule_index_blade_php'
<x-aop-layout>
  <x-slot:title>Schedule</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <h1>Schedule</h1>
  </div>

  <div class="card">
    @if (!$term)
      <h2>No Active Term</h2>
      <p>You must set an active term before scheduling.</p>
      <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
    @else
      <h2>Active Term</h2>
      <p><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
      <div class="actions" style="margin-top:10px; flex-wrap:wrap;">
        <a class="btn" href="{{ route('aop.schedule.offerings.index') }}">Offerings</a>
        <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        <a class="btn" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
        <a class="btn" href="{{ route('aop.schedule.grids.index') }}">Schedule Grids</a>
        <a class="btn" href="{{ route('aop.schedule.reports.index') }}">Schedule Reports</a>
      </div>
    @endif
  </div>
</x-aop-layout>
EOF_resources_views_aop_schedule_index_blade_php

write_file "resources/views/aop/schedule/grids/index.blade.php" <<'EOF_resources_views_aop_schedule_grids_index_blade_php'
<x-aop-layout>
  <x-slot:title>Schedule Grids</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule Grids</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
        <p class="muted">Instructor grid includes office hours. Room grid excludes office hours. Use Print for a printer-friendly layout.</p>
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
      @if(!$term)
        <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
      @endif
    </div>
  </div>

  @if(!$term)
    <div class="card">
      <h2>No Active Term</h2>
      <p>You must set an active term before viewing schedule grids.</p>
    </div>
  @else
    <div class="grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <div class="card">
        <h2>Instructor Grid</h2>
        <p class="muted">Classes + office hours</p>
        <form method="get" action="" onsubmit="return false;">
          <label class="label">Instructor</label>
          <div class="row" style="gap:10px; align-items:flex-end;">
            <select class="input" id="instructorSelect">
              <option value="">Select...</option>
              @foreach($instructors as $ins)
                <option value="{{ $ins->id }}">{{ $ins->name }}</option>
              @endforeach
            </select>
            <button class="btn" type="button" onclick="
              const id=document.getElementById('instructorSelect').value;
              if(!id){ alert('Select an instructor.'); return; }
              window.location='{{ url('/aop/schedule/grids/instructors') }}/'+id;
            ">View</button>
          </div>
        </form>
      </div>

      <div class="card">
        <h2>Room Grid</h2>
        <p class="muted">Classes only</p>
        <form method="get" action="" onsubmit="return false;">
          <label class="label">Room</label>
          <div class="row" style="gap:10px; align-items:flex-end;">
            <select class="input" id="roomSelect">
              <option value="">Select...</option>
              @foreach($rooms as $r)
                <option value="{{ $r->id }}">{{ $r->name }}</option>
              @endforeach
            </select>
            <button class="btn" type="button" onclick="
              const id=document.getElementById('roomSelect').value;
              if(!id){ alert('Select a room.'); return; }
              window.location='{{ url('/aop/schedule/grids/rooms') }}/'+id;
            ">View</button>
          </div>
        </form>
      </div>
    </div>
  @endif
</x-aop-layout>
EOF_resources_views_aop_schedule_grids_index_blade_php

write_file "resources/views/aop/schedule/grids/instructor.blade.php" <<'EOF_resources_views_aop_schedule_grids_instructor_blade_php'
<x-aop-layout>
  <x-slot:title>Instructor Grid</x-slot:title>

  <style>
    .sched-grid { width:100%; border-collapse:collapse; table-layout:fixed; }
    .sched-grid th, .sched-grid td { border:1px solid var(--border); padding:8px; }
    .sched-grid th { background:#fafafa; position:sticky; top:0; z-index:2; }
    .time-col { width:84px; background:#fafafa; position:sticky; left:0; z-index:1; }
    .slot { height:42px; }
    .event { border:1px solid var(--border); border-radius:10px; padding:6px 8px; margin:4px 0; background:white; }
    .event small { display:block; color:var(--muted); margin-top:2px; }
    .event.office { background:#f8fafc; }
    .event.class { background:#ffffff; }
    .muted { color:var(--muted); font-size:12px; }

    @media print {
      .actions, .btn, nav, header { display:none !important; }
      .card { border:none !important; box-shadow:none !important; }
      .sched-grid th { position:static; }
      .time-col { position:static; }
      body { background:#fff !important; }
    }
  </style>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Instructor Grid</h1>
      <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}<span class="muted"> • </span><strong>{{ $instructor->name }}</strong></p>
      <p class="muted">Includes classes + office hours. Window auto-fits scheduled blocks.</p>
    </div>
    @if(!$isPrint)
      <div class="actions">
        <a class="btn secondary" href="{{ route('aop.schedule.grids.index') }}">Back to Grids</a>
        <a class="btn" href="{{ route('aop.schedule.officeHours.show', $instructor) }}">Office Hours</a>
        <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        <a class="btn" href="{{ route('aop.schedule.grids.instructor', $instructor) }}?print=1" target="_blank">Print</a>
      </div>
    @endif
  </div>

  <div class="card" style="overflow:auto;">
    @php
      $slots = $grid['slots'];
      $slotMinutes = $grid['slot_minutes'];
      [$sh,$sm] = array_map('intval', explode(':', $start));
      $startTotal = $sh*60 + $sm;

      $fmt = function(int $minutes) {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
      };

      $dayLabel = function(string $d) {
        return $d;
      };
    @endphp

    @if ($slots <= 0)
      <p>No schedule data for this instructor in the active term.</p>
    @else
      <table class="sched-grid">
        <thead>
          <tr>
            <th class="time-col">Time</th>
            @foreach ($days as $d)
              <th>{{ $dayLabel($d) }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @for ($i = 0; $i < $slots; $i++)
            @php
              $rowTime = $fmt($startTotal + ($i * $slotMinutes));
            @endphp
            <tr>
              <td class="time-col"><span class="muted">{{ $rowTime }}</span></td>

              @foreach ($days as $d)
                @php $cell = $grid['cells'][$d][$i]; @endphp

                @if (is_array($cell) && ($cell['type'] ?? null) === 'skip')
                  @continue
                @endif

                @if (is_array($cell) && ($cell['type'] ?? null) === 'cell')
                  <td class="slot" rowspan="{{ $cell['rowspan'] }}">
                    @foreach ($cell['events'] as $ev)
                      @php
                        $klass = $ev['kind'] === 'office' ? 'office' : 'class';
                        $timeRange = $ev['starts_at'] . '–' . $ev['ends_at'];
                      @endphp
                      <div class="event {{ $klass }}">
                        <div style="font-weight:600;">{{ $ev['label'] }}</div>
                        <small>{{ $timeRange }}</small>
                      </div>
                    @endforeach
                  </td>
                @else
                  <td class="slot"></td>
                @endif
              @endforeach
            </tr>
          @endfor
        </tbody>
      </table>
    @endif
  </div>

  @if($isPrint)
    <script>window.addEventListener('load', () => { window.print(); });</script>
  @endif
</x-aop-layout>
EOF_resources_views_aop_schedule_grids_instructor_blade_php

write_file "resources/views/aop/schedule/grids/room.blade.php" <<'EOF_resources_views_aop_schedule_grids_room_blade_php'
<x-aop-layout>
  <x-slot:title>Room Grid</x-slot:title>

  <style>
    .sched-grid { width:100%; border-collapse:collapse; table-layout:fixed; }
    .sched-grid th, .sched-grid td { border:1px solid var(--border); padding:8px; }
    .sched-grid th { background:#fafafa; position:sticky; top:0; z-index:2; }
    .time-col { width:84px; background:#fafafa; position:sticky; left:0; z-index:1; }
    .slot { height:42px; }
    .event { border:1px solid var(--border); border-radius:10px; padding:6px 8px; margin:4px 0; background:white; }
    .event small { display:block; color:var(--muted); margin-top:2px; }
    .muted { color:var(--muted); font-size:12px; }

    @media print {
      .actions, .btn, nav, header { display:none !important; }
      .card { border:none !important; box-shadow:none !important; }
      .sched-grid th { position:static; }
      .time-col { position:static; }
      body { background:#fff !important; }
    }
  </style>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Room Grid</h1>
      <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}<span class="muted"> • </span><strong>{{ $room->name }}</strong></p>
      <p class="muted">Includes classes only. Window auto-fits scheduled blocks.</p>
    </div>
    @if(!$isPrint)
      <div class="actions">
        <a class="btn secondary" href="{{ route('aop.schedule.grids.index') }}">Back to Grids</a>
        <a class="btn" href="{{ route('aop.rooms.index') }}">Rooms</a>
        <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        <a class="btn" href="{{ route('aop.schedule.grids.room', $room) }}?print=1" target="_blank">Print</a>
      </div>
    @endif
  </div>

  <div class="card" style="overflow:auto;">
    @php
      $slots = $grid['slots'];
      $slotMinutes = $grid['slot_minutes'];
      [$sh,$sm] = array_map('intval', explode(':', $start));
      $startTotal = $sh*60 + $sm;

      $fmt = function(int $minutes) {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
      };
    @endphp

    @if ($slots <= 0)
      <p>No schedule data for this room in the active term.</p>
    @else
      <table class="sched-grid">
        <thead>
          <tr>
            <th class="time-col">Time</th>
            @foreach ($days as $d)
              <th>{{ $d }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @for ($i = 0; $i < $slots; $i++)
            @php
              $rowTime = $fmt($startTotal + ($i * $slotMinutes));
            @endphp
            <tr>
              <td class="time-col"><span class="muted">{{ $rowTime }}</span></td>

              @foreach ($days as $d)
                @php $cell = $grid['cells'][$d][$i]; @endphp

                @if (is_array($cell) && ($cell['type'] ?? null) === 'skip')
                  @continue
                @endif

                @if (is_array($cell) && ($cell['type'] ?? null) === 'cell')
                  <td class="slot" rowspan="{{ $cell['rowspan'] }}">
                    @foreach ($cell['events'] as $ev)
                      @php $timeRange = $ev['starts_at'] . '–' . $ev['ends_at']; @endphp
                      <div class="event">
                        <div style="font-weight:600;">{{ $ev['label'] }}</div>
                        <small>{{ $timeRange }}</small>
                      </div>
                    @endforeach
                  </td>
                @else
                  <td class="slot"></td>
                @endif
              @endforeach
            </tr>
          @endfor
        </tbody>
      </table>
    @endif
  </div>

  @if($isPrint)
    <script>window.addEventListener('load', () => { window.print(); });</script>
  @endif
</x-aop-layout>
EOF_resources_views_aop_schedule_grids_room_blade_php

write_file "resources/views/aop/schedule/reports/index.blade.php" <<'EOF_resources_views_aop_schedule_reports_index_blade_php'
<x-aop-layout>
  <x-slot:title>Schedule Reports</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule Reports</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
        <p class="muted">Exports are scoped to the active term.</p>
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
      @if(!$term)
        <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
      @endif
    </div>
  </div>

  @if(!$term)
    <div class="card">
      <h2>No Active Term</h2>
      <p>You must set an active term before schedule reports and exports can be generated.</p>
    </div>
  @else
    <div class="grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <div class="card">
        <h2>Overview</h2>
        <div class="muted" style="margin-top:8px;">
          <div><strong>Offerings:</strong> {{ $stats['offerings'] ?? 0 }}</div>
          <div><strong>Sections:</strong> {{ $stats['sections'] ?? 0 }}</div>
          <div><strong>Meeting Blocks:</strong> {{ $stats['meeting_blocks'] ?? 0 }}</div>
          <div><strong>Office Hours Blocks:</strong> {{ $stats['office_hours_blocks'] ?? 0 }}</div>
        </div>

        <div style="margin-top:12px;">
          <a class="btn" href="{{ route('aop.schedule.reports.exportTerm') }}">Export Term Schedule (CSV)</a>
        </div>
      </div>

      <div class="card">
        <h2>Quick Links</h2>
        <p class="muted">Jump to grids, then use Print if needed.</p>
        <div class="actions" style="margin-top:10px; flex-wrap:wrap;">
          <a class="btn" href="{{ route('aop.schedule.grids.index') }}">Schedule Grids</a>
          <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
          <a class="btn" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
        </div>
      </div>
    </div>

    <div class="grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top:14px;">
      <div class="card">
        <h2>Export Instructor Schedule</h2>
        <p class="muted">Includes classes + office hours.</p>
        <div class="row" style="gap:10px; align-items:flex-end;">
          <div style="flex:1;">
            <label class="label">Instructor</label>
            <select class="input" id="instructorSelect">
              <option value="">Select...</option>
              @foreach($instructors as $ins)
                <option value="{{ $ins->id }}">{{ $ins->name }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <button class="btn" type="button" onclick="
              const id=document.getElementById('instructorSelect').value;
              if(!id){ alert('Select an instructor.'); return; }
              window.location='{{ url('/aop/schedule/reports/export/instructors') }}/'+id;
            ">Download CSV</button>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>Export Room Schedule</h2>
        <p class="muted">Includes classes only (office hours excluded).</p>
        <div class="row" style="gap:10px; align-items:flex-end;">
          <div style="flex:1;">
            <label class="label">Room</label>
            <select class="input" id="roomSelect">
              <option value="">Select...</option>
              @foreach($rooms as $r)
                <option value="{{ $r->id }}">{{ $r->name }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <button class="btn" type="button" onclick="
              const id=document.getElementById('roomSelect').value;
              if(!id){ alert('Select a room.'); return; }
              window.location='{{ url('/aop/schedule/reports/export/rooms') }}/'+id;
            ">Download CSV</button>
          </div>
        </div>
      </div>
    </div>

    <div class="grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top:14px;">
      <div class="card">
        <h2>Unassigned</h2>

        <details open style="margin-top:8px;">
          <summary style="cursor:pointer; font-weight:600;">Sections Missing Instructor ({{ $unassigned['sections_missing_instructor']->count() }})</summary>
          <div style="margin-top:8px;">
            @if($unassigned['sections_missing_instructor']->count() === 0)
              <div class="muted">None</div>
            @else
              <ul>
                @foreach($unassigned['sections_missing_instructor'] as $s)
                  <li>
                    {{ $s->offering->catalogCourse->code }} {{ $s->section_code }}
                    <span class="muted"> — {{ $s->offering->catalogCourse->title }}</span>
                  </li>
                @endforeach
              </ul>
            @endif
          </div>
        </details>

        <details style="margin-top:10px;">
          <summary style="cursor:pointer; font-weight:600;">Sections Missing Meeting Blocks ({{ $unassigned['sections_missing_meeting_blocks']->count() }})</summary>
          <div style="margin-top:8px;">
            @if($unassigned['sections_missing_meeting_blocks']->count() === 0)
              <div class="muted">None</div>
            @else
              <ul>
                @foreach($unassigned['sections_missing_meeting_blocks'] as $s)
                  <li>
                    {{ $s->offering->catalogCourse->code }} {{ $s->section_code }}
                    <span class="muted"> — {{ $s->instructor?->name ?? 'TBD Instructor' }}</span>
                  </li>
                @endforeach
              </ul>
            @endif
          </div>
        </details>

        <details style="margin-top:10px;">
          <summary style="cursor:pointer; font-weight:600;">Meeting Blocks Missing Room ({{ $unassigned['meeting_blocks_missing_room']->count() }})</summary>
          <div style="margin-top:8px;">
            @if($unassigned['meeting_blocks_missing_room']->count() === 0)
              <div class="muted">None</div>
            @else
              <ul>
                @foreach($unassigned['meeting_blocks_missing_room'] as $mb)
                  <li>
                    {{ $mb->section->offering->catalogCourse->code }} {{ $mb->section->section_code }}
                    <span class="muted"> — {{ $mb->section->instructor?->name ?? 'TBD Instructor' }}</span>
                    <span class="muted"> ({{ substr((string)$mb->starts_at,0,5) }}–{{ substr((string)$mb->ends_at,0,5) }})</span>
                  </li>
                @endforeach
              </ul>
            @endif
          </div>
        </details>
      </div>

      <div class="card">
        <h2>Counts</h2>

        <div style="margin-top:10px;">
          <h3 style="margin:0 0 6px 0;">By Modality</h3>
          @if(empty($stats['modalities']))
            <div class="muted">No data</div>
          @else
            <ul>
              @foreach($stats['modalities'] as $k => $v)
                <li>{{ $k }}: <strong>{{ $v }}</strong></li>
              @endforeach
            </ul>
          @endif
        </div>

        <div style="margin-top:14px;">
          <h3 style="margin:0 0 6px 0;">By Meeting Type</h3>
          @if(empty($stats['meeting_types']))
            <div class="muted">No data</div>
          @else
            <ul>
              @foreach($stats['meeting_types'] as $k => $v)
                <li>{{ $k }}: <strong>{{ $v }}</strong></li>
              @endforeach
            </ul>
          @endif
        </div>
      </div>
    </div>
  @endif
</x-aop-layout>
EOF_resources_views_aop_schedule_reports_index_blade_php

# Permissions: keep app readable; runtime dirs writable
if id www-data >/dev/null 2>&1; then
  chown -R www-data:www-data "$ROOT_DIR/storage" "$ROOT_DIR/bootstrap/cache" 2>/dev/null || true
fi
find "$ROOT_DIR" -type d -exec chmod 755 {} \; 2>/dev/null || true
find "$ROOT_DIR" -type f -exec chmod 644 {} \; 2>/dev/null || true
chmod 755 "$ROOT_DIR/phase-7-schedule-reports-export.sh" 2>/dev/null || true
echo "OK: Phase 7 applied (Schedule Reports + CSV exports + print-friendly grids)."

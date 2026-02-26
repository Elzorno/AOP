#!/usr/bin/env bash
set -euo pipefail

umask 022

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

write_file() {
  local path="$1"
  local tmp
  tmp="$(mktemp)"
  cat > "$tmp"
  mkdir -p "$(dirname "$path")"
  cat "$tmp" > "$path"
  rm -f "$tmp"
}

# routes/web.php
write_file "routes/web.php" <<'PHP'
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
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
PHP

# resources/views/aop/schedule/index.blade.php
write_file "resources/views/aop/schedule/index.blade.php" <<'BLADE'
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
      <div class="actions" style="margin-top:10px;">
        <a class="btn" href="{{ route('aop.schedule.offerings.index') }}">Offerings</a>
        <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        <a class="btn" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
        <a class="btn" href="{{ route('aop.schedule.grids.index') }}">Schedule Grids</a>
      </div>
    @endif
  </div>
</x-aop-layout>
BLADE

# app/Http/Controllers/Aop/Schedule/ScheduleGridController.php
write_file "app/Http/Controllers/Aop/Schedule/ScheduleGridController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Schedule;

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
            $label = sprintf('%s %s (%s) — %s', $course->code, $mb->section->section_code, (string)$mb->type, $roomName);

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
            $label = sprintf('%s %s (%s) — %s', $course->code, $mb->section->section_code, (string)$mb->type, $instructorName);

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

# resources/views/aop/schedule/grids/index.blade.php
write_file "resources/views/aop/schedule/grids/index.blade.php" <<'BLADE'
<x-aop-layout>
  <x-slot:title>Schedule Grids</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule Grids</h1>
      <p style="margin-top:6px;">Instructor grid includes office hours. Room grid excludes office hours.</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
    </div>
  </div>

  <div class="card">
    @if (!$term)
      <h2>No Active Term</h2>
      <p>You must set an active term before viewing grids.</p>
      <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
    @else
      <h2>Active Term</h2>
      <p><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>

      <div class="grid" style="margin-top:14px;">
        <div class="col-6">
          <div class="card" style="border-radius:14px;">
            <h2>Instructor Grid</h2>
            <p>Select an instructor to view their weekly grid (classes + office hours).</p>
            <form method="GET" action="{{ route('aop.schedule.grids.index') }}" onsubmit="return false;">
              <label>Instructor</label>
              <select id="instructor_select">
                <option value="">-- Select --</option>
                @foreach ($instructors as $i)
                  <option value="{{ $i->id }}">{{ $i->name }}</option>
                @endforeach
              </select>
              <div class="actions" style="margin-top:10px;">
                <a class="btn" href="#" onclick="var id=document.getElementById('instructor_select').value; if(!id){alert('Select an instructor.'); return false;} window.location='{{ url('/aop/schedule/grids/instructors') }}/'+id; return false;">View Instructor Grid</a>
              </div>
            </form>
          </div>
        </div>

        <div class="col-6">
          <div class="card" style="border-radius:14px;">
            <h2>Room Grid</h2>
            <p>Select a room to view its weekly grid (classes only).</p>
            <form method="GET" action="{{ route('aop.schedule.grids.index') }}" onsubmit="return false;">
              <label>Room</label>
              <select id="room_select">
                <option value="">-- Select --</option>
                @foreach ($rooms as $r)
                  <option value="{{ $r->id }}">{{ $r->name }}</option>
                @endforeach
              </select>
              <div class="actions" style="margin-top:10px;">
                <a class="btn" href="#" onclick="var id=document.getElementById('room_select').value; if(!id){alert('Select a room.'); return false;} window.location='{{ url('/aop/schedule/grids/rooms') }}/'+id; return false;">View Room Grid</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    @endif
  </div>
</x-aop-layout>
BLADE

# resources/views/aop/schedule/grids/instructor.blade.php
write_file "resources/views/aop/schedule/grids/instructor.blade.php" <<'BLADE'
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
  </style>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Instructor Grid</h1>
      <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}<span class="muted"> • </span><strong>{{ $instructor->name }}</strong></p>
      <p class="muted">Includes classes + office hours. Window auto-fits scheduled blocks.</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.grids.index') }}">Back to Grids</a>
      <a class="btn" href="{{ route('aop.schedule.officeHours.show', $instructor) }}">Office Hours</a>
      <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
    </div>
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
</x-aop-layout>
BLADE

# resources/views/aop/schedule/grids/room.blade.php
write_file "resources/views/aop/schedule/grids/room.blade.php" <<'BLADE'
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
  </style>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Room Grid</h1>
      <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}<span class="muted"> • </span><strong>{{ $room->name }}</strong></p>
      <p class="muted">Classes only (office hours excluded). Window auto-fits scheduled blocks.</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.grids.index') }}">Back to Grids</a>
      <a class="btn" href="{{ route('aop.rooms.edit', $room) }}">Edit Room</a>
      <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
    </div>
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
      <p>No schedule data for this room in the active term.</p>
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
                        $timeRange = $ev['starts_at'] . '–' . $ev['ends_at'];
                      @endphp
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
</x-aop-layout>
BLADE

chmod 0644 routes/web.php
chmod 0644 resources/views/aop/schedule/index.blade.php
chmod 0644 app/Http/Controllers/Aop/Schedule/ScheduleGridController.php
chmod 0644 resources/views/aop/schedule/grids/index.blade.php
chmod 0644 resources/views/aop/schedule/grids/instructor.blade.php
chmod 0644 resources/views/aop/schedule/grids/room.blade.php

# Make this script executable for convenience (if the filesystem allows it)
chmod +x "$0" 2>/dev/null || true

echo "Phase 6 applied: Schedule Grids (Instructor + Room)."

#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

write_file() {
  local rel="$1"
  local tmp
  tmp="$(mktemp)"
  cat >"$tmp"
  mkdir -p "$(dirname "$ROOT_DIR/$rel")"
  cp "$tmp" "$ROOT_DIR/$rel"
  rm -f "$tmp"
}

# Controller
write_file "app/Http/Controllers/Aop/Syllabi/SyllabiController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Syllabi;

use App\Enums\MeetingBlockType;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\SchedulePublication;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class SyllabiController extends Controller
{
    private const DAYS_ORDER = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

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
        $latestPublication = null;

        $sections = collect();
        if ($term) {
            $sections = Section::query()
                ->with(['offering.catalogCourse', 'instructor', 'meetingBlocks.room'])
                ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
                ->orderBy('section_code')
                ->get();

            $latestPublication = SchedulePublication::where('term_id', $term->id)->orderByDesc('version')->first();
        }

        return view('aop.syllabi.index', [
            'term' => $term,
            'sections' => $sections,
            'latestPublication' => $latestPublication,
        ]);
    }

    public function show(Section $section)
    {
        $term = $this->activeTermOrFail();
        abort_unless($section->offering && $section->offering->term_id === $term->id, 404);

        $data = $this->buildSyllabusDataFromSection($term, $section);

        return view('aop.syllabi.show', [
            'term' => $term,
            'section' => $section,
            'syllabus' => $data,
        ]);
    }

    public function downloadHtml(Section $section): StreamedResponse
    {
        $term = $this->activeTermOrFail();
        abort_unless($section->offering && $section->offering->term_id === $term->id, 404);

        $data = $this->buildSyllabusDataFromSection($term, $section);

        $html = view('aop.syllabi.render', [
            'term' => $term,
            'section' => $section,
            'syllabus' => $data,
        ])->render();

        $filename = sprintf('syllabus_%s_%s_%s.html', $term->code, $data['course_code'], $this->safeSlug($section->section_code));

        return response()->streamDownload(function () use ($html) {
            echo $html;
        }, $filename, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function downloadJson(Section $section): StreamedResponse
    {
        $term = $this->activeTermOrFail();
        abort_unless($section->offering && $section->offering->term_id === $term->id, 404);

        $data = $this->buildSyllabusDataFromSection($term, $section);
        $filename = sprintf('syllabus_%s_%s_%s.json', $term->code, $data['course_code'], $this->safeSlug($section->section_code));

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = "{}";
        }

        return response()->streamDownload(function () use ($json) {
            echo $json;
        }, $filename, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public function generateBundle(SchedulePublication $publication): StreamedResponse
    {
        $term = $this->activeTermOrFail();
        abort_unless($publication->term_id === $term->id, 404);

        $sections = Section::query()
            ->with(['offering.catalogCourse', 'instructor', 'meetingBlocks.room'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
            ->orderBy('section_code')
            ->get();

        $base = sprintf('aop/syllabi/%s/v%d', $term->code, $publication->version);
        $disk = Storage::disk('local');
        if (!$disk->exists($base)) {
            $disk->makeDirectory($base);
        }

        foreach ($sections as $section) {
            $data = $this->buildSyllabusDataFromSection($term, $section);

            $html = view('aop.syllabi.render', [
                'term' => $term,
                'section' => $section,
                'syllabus' => $data,
            ])->render();

            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = "{}";
            }

            $stub = sprintf('%s_%s', $data['course_code'], $this->safeSlug($section->section_code));
            $disk->put($base . '/' . $stub . '.html', $html);
            $disk->put($base . '/' . $stub . '.json', $json);
        }

        $zipStoragePath = $base . '/syllabi_bundle.zip';
        $this->createZipFromDir($base, $zipStoragePath);

        $downloadName = sprintf('aop_%s_v%d_syllabi_html_json.zip', $term->code, $publication->version);

        return response()->streamDownload(function () use ($zipStoragePath) {
            $stream = Storage::disk('local')->readStream($zipStoragePath);
            if (!$stream) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        }, $downloadName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    private function createZipFromDir(string $dirPath, string $zipPath): void
    {
        $disk = Storage::disk('local');
        if (!$disk->exists($dirPath)) {
            return;
        }

        $fullZipPath = storage_path('app/' . ltrim($zipPath, '/'));
        $zipParent = dirname($fullZipPath);
        if (!is_dir($zipParent)) {
            @mkdir($zipParent, 0755, true);
        }

        if (file_exists($fullZipPath)) {
            @unlink($fullZipPath);
        }

        $zip = new ZipArchive();
        $ok = $zip->open($fullZipPath, ZipArchive::CREATE);
        if ($ok !== true) {
            return;
        }

        foreach ($disk->allFiles($dirPath) as $file) {
            if ($file === $zipPath) {
                continue;
            }
            $absPath = storage_path('app/' . ltrim($file, '/'));
            if (!is_file($absPath)) {
                continue;
            }
            $localName = str_replace($dirPath . '/', '', $file);
            $zip->addFile($absPath, $localName);
        }

        $zip->close();
    }

    private function buildSyllabusDataFromSection(Term $term, Section $section): array
    {
        $course = $section->offering->catalogCourse;
        $meetingBlocks = $section->meetingBlocks->sortBy('starts_at')->values();

        $meetings = $meetingBlocks->map(function (MeetingBlock $mb) {
            return [
                'type' => $this->meetingTypeLabel($mb->type),
                'days' => $mb->days_json ?? [],
                'start' => $this->time5($mb->starts_at),
                'end' => $this->time5($mb->ends_at),
                'room' => $mb->room?->name ?? 'TBD',
                'notes' => $mb->notes ?? null,
            ];
        })->all();

        $instructor = $section->instructor;
        $officeHours = [];
        if ($instructor) {
            $officeHours = OfficeHourBlock::query()
                ->where('term_id', $term->id)
                ->where('instructor_id', $instructor->id)
                ->orderBy('starts_at')
                ->get()
                ->map(fn (OfficeHourBlock $ob) => [
                    'days' => $ob->days_json ?? [],
                    'start' => $this->time5($ob->starts_at),
                    'end' => $this->time5($ob->ends_at),
                    'notes' => $ob->notes ?? null,
                ])->all();
        }

        return [
            'term' => [
                'code' => $term->code,
                'name' => $term->name,
            ],
            'course_code' => $course->code,
            'course_title' => $course->title,
            'section_code' => $section->section_code,
            'credits_text' => $course->credits_text,
            'credits_min' => $course->credits_min,
            'credits_max' => $course->credits_max,
            'contact_hours_per_week' => $course->contact_hours_per_week,
            'course_lab_fee' => $course->course_lab_fee,
            'prereq' => $course->prereq_text,
            'coreq' => $course->coreq_text,
            'description' => $course->description,
            'notes' => $course->notes,
            'section_notes' => $section->notes,
            'modality' => $section->modality?->value ?? (string)$section->modality,
            'instructor' => $instructor ? [
                'name' => $instructor->name,
                'email' => $instructor->email,
                'is_full_time' => $instructor->is_full_time,
            ] : null,
            'meetings' => $meetings,
            'office_hours' => $officeHours,
            'policies' => [
                'attendance' => $this->defaultAttendancePolicy(),
                'integrity' => $this->defaultIntegrityPolicy(),
                'accommodations' => $this->defaultAccommodationsPolicy(),
            ],
        ];
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

    private function defaultAttendancePolicy(): string
    {
        return 'Attendance is expected. If you must miss class, contact the instructor as soon as possible. Hands-on courses require participation to meet learning outcomes.';
    }

    private function defaultIntegrityPolicy(): string
    {
        return 'Academic integrity is required. Submitting work that is not your own, unauthorized collaboration, or misuse of tools/resources may result in disciplinary action per university policy.';
    }

    private function defaultAccommodationsPolicy(): string
    {
        return 'Students requiring accommodations should contact the appropriate campus office and notify the instructor early in the term so arrangements can be made.';
    }
}
PHP

# Views
write_file "resources/views/aop/syllabi/index.blade.php" <<'BLADE'
<x-aop-layout>
  <x-slot:title>Syllabi</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabi</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
        @if($latestPublication)
          <p class="muted">Latest published: <span class="badge">v{{ $latestPublication->version }}</span> {{ $latestPublication->published_at->format('Y-m-d H:i') }}</p>
        @else
          <p class="muted">Latest published: <span class="badge">None</span></p>
        @endif
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
      <p>You must set an active term before generating syllabi.</p>
    </div>
  @else
    <div class="card">
      <h2>Syllabus Bundle</h2>
      <p class="muted">This generates a ZIP containing HTML + JSON syllabi for all sections in the active term (based on current schedule data). DOCX/PDF rendering will be added in a later phase.</p>

      @if($latestPublication)
        <form method="POST" action="{{ route('aop.syllabi.bundle', $latestPublication) }}" style="margin-top:10px;">
          @csrf
          <button class="btn" type="submit">Generate ZIP for Published v{{ $latestPublication->version }}</button>
        </form>
      @else
        <p class="muted" style="margin-top:10px;">Publish a snapshot to enable bundle generation.</p>
        <a class="btn" href="{{ route('aop.schedule.publish.index') }}">Go to Publish Snapshots</a>
      @endif
    </div>

    <div style="height:14px;"></div>

    <div class="card">
      <h2>Sections</h2>
      @if($sections->count() === 0)
        <p class="muted">No sections exist for the active term.</p>
      @else
        <table style="margin-top:8px;">
          <thead>
            <tr>
              <th style="width:120px;">Course</th>
              <th>Title</th>
              <th style="width:90px;">Section</th>
              <th style="width:180px;">Instructor</th>
              <th style="width:120px;">Modality</th>
              <th style="width:260px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            @foreach($sections as $s)
              @php $course = $s->offering->catalogCourse; @endphp
              <tr>
                <td><strong>{{ $course->code }}</strong></td>
                <td class="muted">{{ $course->title }}</td>
                <td>{{ $s->section_code }}</td>
                <td class="muted">{{ $s->instructor?->name ?? 'TBD' }}</td>
                <td class="muted">{{ $s->modality?->value ?? (string)$s->modality }}</td>
                <td>
                  <div class="actions" style="gap:8px; flex-wrap:wrap;">
                    <a class="btn secondary" href="{{ route('aop.syllabi.show', $s) }}">View</a>
                    <a class="btn secondary" href="{{ route('aop.syllabi.downloadHtml', $s) }}">HTML</a>
                    <a class="btn secondary" href="{{ route('aop.syllabi.downloadJson', $s) }}">JSON</a>
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  @endif
</x-aop-layout>
BLADE

write_file "resources/views/aop/syllabi/show.blade.php" <<'BLADE'
<x-aop-layout>
  <x-slot:title>Syllabus</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabus</h1>
      <p style="margin-top:6px;"><strong>{{ $syllabus['course_code'] }}</strong> — {{ $syllabus['course_title'] }} ({{ $syllabus['section_code'] }})</p>
      <p class="muted">{{ $term->code }} — {{ $term->name }}</p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadHtml', $section) }}">Download HTML</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadJson', $section) }}">Download JSON</a>
      <a class="btn" href="#" onclick="window.open('{{ route('aop.syllabi.show', $section) }}?print=1','_blank'); return false;">Print</a>
    </div>
  </div>

  <div class="card">
    @include('aop.syllabi.partials.syllabus', ['syllabus' => $syllabus])
  </div>
</x-aop-layout>
BLADE

write_file "resources/views/aop/syllabi/render.blade.php" <<'BLADE'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Syllabus</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; color:#111; line-height:1.35; margin:24px; }
    h2,h3,h4 { margin: 0 0 8px 0; }
    .muted { color:#555; }
    ul { margin: 0; padding-left:18px; }
  </style>
</head>
<body>
  @include('aop.syllabi.partials.syllabus', ['syllabus' => $syllabus])
</body>
</html>
BLADE

write_file "resources/views/aop/syllabi/partials/syllabus.blade.php" <<'BLADE'
@php
  $print = request()->query('print') == '1';
@endphp

@if($print)
  <style>
    body { background: #fff !important; }
    .card, .row, header, nav, .actions { display: none !important; }
    .syllabi-print { display:block !important; }
  </style>
@endif

<div class="syllabi-print" style="display:block;">
  <div style="border-bottom:1px solid #ddd; padding-bottom:10px; margin-bottom:10px;">
    <h2 style="margin:0;">{{ $syllabus['course_code'] }} — {{ $syllabus['course_title'] }}</h2>
    <div class="muted" style="margin-top:4px;">
      Section: <strong>{{ $syllabus['section_code'] }}</strong>
      &nbsp;•&nbsp; Term: <strong>{{ $syllabus['term']['code'] }}</strong>
      &nbsp;•&nbsp; Modality: <strong>{{ $syllabus['modality'] }}</strong>
    </div>
  </div>

  <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
    <div>
      <h3 style="margin:0 0 6px 0;">Instructor</h3>
      @if($syllabus['instructor'])
        <div><strong>{{ $syllabus['instructor']['name'] }}</strong></div>
        <div class="muted">{{ $syllabus['instructor']['email'] }}</div>
      @else
        <div class="muted">TBD</div>
      @endif

      <h3 style="margin:12px 0 6px 0;">Office Hours</h3>
      @if(count($syllabus['office_hours']) === 0)
        <div class="muted">Not set.</div>
      @else
        <ul style="margin:0; padding-left:18px;">
          @foreach($syllabus['office_hours'] as $oh)
            <li>
              <strong>{{ implode('/', $oh['days']) }}</strong>
              {{ $oh['start'] }}–{{ $oh['end'] }}
              @if(!empty($oh['notes']))<span class="muted"> — {{ $oh['notes'] }}</span>@endif
            </li>
          @endforeach
        </ul>
      @endif
    </div>

    <div>
      <h3 style="margin:0 0 6px 0;">Course Details</h3>
      <div class="muted">Credits: <strong>{{ $syllabus['credits_text'] ?? ($syllabus['credits_min'].'-'.$syllabus['credits_max']) }}</strong></div>
      @if(!is_null($syllabus['contact_hours_per_week']))
        <div class="muted">Contact Hours/Week: <strong>{{ $syllabus['contact_hours_per_week'] }}</strong></div>
      @endif
      @if(!empty($syllabus['course_lab_fee']))
        <div class="muted">Lab Fee: <strong>${{ number_format((float)$syllabus['course_lab_fee'], 2) }}</strong></div>
      @endif

      <h3 style="margin:12px 0 6px 0;">Meeting Times</h3>
      @if(count($syllabus['meetings']) === 0)
        <div class="muted">No meeting blocks have been scheduled.</div>
      @else
        <ul style="margin:0; padding-left:18px;">
          @foreach($syllabus['meetings'] as $m)
            <li>
              <strong>{{ $m['type'] }}</strong>
              — <strong>{{ implode('/', $m['days']) }}</strong>
              {{ $m['start'] }}–{{ $m['end'] }}
              — {{ $m['room'] }}
              @if(!empty($m['notes']))<span class="muted"> — {{ $m['notes'] }}</span>@endif
            </li>
          @endforeach
        </ul>
      @endif
    </div>
  </div>

  <div style="margin-top:14px;">
    <h3>Prerequisites / Co-requisites</h3>
    <div class="muted">Prereq: {{ $syllabus['prereq'] ?: 'None listed.' }}</div>
    <div class="muted">Co-req: {{ $syllabus['coreq'] ?: 'None listed.' }}</div>
  </div>

  <div style="margin-top:14px;">
    <h3>Course Description</h3>
    <div class="muted" style="white-space:pre-wrap;">{{ $syllabus['description'] ?: 'No description.' }}</div>
    @if(!empty($syllabus['notes']))
      <div style="margin-top:8px;" class="muted"><strong>Catalog Notes:</strong> {{ $syllabus['notes'] }}</div>
    @endif
    @if(!empty($syllabus['section_notes']))
      <div style="margin-top:8px;" class="muted"><strong>Section Notes:</strong> {{ $syllabus['section_notes'] }}</div>
    @endif
  </div>

  <div style="margin-top:14px;">
    <h3>Policies</h3>
    <h4 style="margin-bottom:4px;">Attendance</h4>
    <div class="muted">{{ $syllabus['policies']['attendance'] }}</div>

    <h4 style="margin:10px 0 4px 0;">Academic Integrity</h4>
    <div class="muted">{{ $syllabus['policies']['integrity'] }}</div>

    <h4 style="margin:10px 0 4px 0;">Accommodations</h4>
    <div class="muted">{{ $syllabus['policies']['accommodations'] }}</div>
  </div>
</div>

@if($print)
  <script>window.print();</script>
@endif
BLADE

# Update schedule home view to include Syllabi
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
      @if($latestPublication)
        <p class="muted">Published: <span class="badge">v{{ $latestPublication->version }}</span> {{ $latestPublication->published_at->format('Y-m-d H:i') }}</p>
      @else
        <p class="muted">Published: <span class="badge">None</span></p>
      @endif
      <div class="actions" style="margin-top:10px; flex-wrap:wrap;">
        <a class="btn" href="{{ route('aop.schedule.offerings.index') }}">Offerings</a>
        <a class="btn" href="{{ route('aop.schedule.sections.index') }}">Sections</a>
        <a class="btn" href="{{ route('aop.schedule.officeHours.index') }}">Office Hours</a>
        <a class="btn" href="{{ route('aop.schedule.grids.index') }}">Schedule Grids</a>
        <a class="btn" href="{{ route('aop.schedule.reports.index') }}">Schedule Reports</a>
        <a class="btn" href="{{ route('aop.schedule.publish.index') }}">Publish Snapshots</a>
        <a class="btn" href="{{ route('aop.syllabi.index') }}">Syllabi</a>
      </div>
    @endif
  </div>
</x-aop-layout>
BLADE

# Update routes
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
use App\Http\Controllers\Aop\Schedule\ScheduleReportsController;
use App\Http\Controllers\Aop\Schedule\OfficeHoursController;
use App\Http\Controllers\Aop\Schedule\SchedulePublishController;
use App\Http\Controllers\Aop\Syllabi\SyllabiController;
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

        // Publish Snapshots (active term)
        Route::get('/schedule/publish', [SchedulePublishController::class, 'index'])->name('schedule.publish.index');
        Route::post('/schedule/publish', [SchedulePublishController::class, 'store'])->name('schedule.publish.store');
        Route::get('/schedule/publish/{publication}/download/term', [SchedulePublishController::class, 'downloadTerm'])->name('schedule.publish.downloadTerm');
        Route::get('/schedule/publish/{publication}/download/instructors', [SchedulePublishController::class, 'downloadInstructorsZip'])->name('schedule.publish.downloadInstructorsZip');
        Route::get('/schedule/publish/{publication}/download/rooms', [SchedulePublishController::class, 'downloadRoomsZip'])->name('schedule.publish.downloadRoomsZip');

        // Syllabi (active term)
        Route::get('/syllabi', [SyllabiController::class, 'index'])->name('syllabi.index');
        Route::get('/syllabi/sections/{section}', [SyllabiController::class, 'show'])->name('syllabi.show');
        Route::get('/syllabi/sections/{section}/download/html', [SyllabiController::class, 'downloadHtml'])->name('syllabi.downloadHtml');
        Route::get('/syllabi/sections/{section}/download/json', [SyllabiController::class, 'downloadJson'])->name('syllabi.downloadJson');
        Route::post('/syllabi/bundle/{publication}', [SyllabiController::class, 'generateBundle'])->name('syllabi.bundle');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

// Public read-only published schedule (Phase 9)
Route::get('/p/{termCode}/{version?}/{token}', [\App\Http\Controllers\Public\SchedulePublicController::class, 'show'])
    ->whereNumber('version')
    ->name('public.schedule.show');

Route::get('/p/{termCode}/{version}/{token}/download/term', [\App\Http\Controllers\Public\SchedulePublicController::class, 'downloadTerm'])
    ->whereNumber('version')
    ->name('public.schedule.download.term');

Route::get('/p/{termCode}/{version}/{token}/download/instructors', [\App\Http\Controllers\Public\SchedulePublicController::class, 'downloadInstructorsZip'])
    ->whereNumber('version')
    ->name('public.schedule.download.instructors');

Route::get('/p/{termCode}/{version}/{token}/download/rooms', [\App\Http\Controllers\Public\SchedulePublicController::class, 'downloadRoomsZip'])
    ->whereNumber('version')
    ->name('public.schedule.download.rooms');
PHP

chmod 644 "$ROOT_DIR/routes/web.php" "$ROOT_DIR/app/Http/Controllers/Aop/Syllabi/SyllabiController.php" \
  "$ROOT_DIR/resources/views/aop/syllabi/index.blade.php" "$ROOT_DIR/resources/views/aop/syllabi/show.blade.php" \
  "$ROOT_DIR/resources/views/aop/syllabi/render.blade.php" "$ROOT_DIR/resources/views/aop/syllabi/partials/syllabus.blade.php" \
  "$ROOT_DIR/resources/views/aop/schedule/index.blade.php"

echo "OK: Phase 11 syllabi (HTML+JSON) applied."

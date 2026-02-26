#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

write_file() {
  local rel="$1"
  local tmp
  tmp="$(mktemp)"
  cat > "$tmp"
  mkdir -p "$(dirname "$ROOT_DIR/$rel")"
  cat "$tmp" > "$ROOT_DIR/$rel"
  rm -f "$tmp"
}

# ------------------------------
# Migration
# ------------------------------
write_file "database/migrations/2026_02_26_000010_create_schedule_publications_table.php" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_publications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->unsignedInteger('version');

            $table->text('notes')->nullable();

            $table->dateTime('published_at');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Base directory under storage/app (local disk), e.g. "aop/published/2026SP/v3"
            $table->string('storage_base_path', 255);

            $table->timestamps();

            $table->unique(['term_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_publications');
    }
};
PHP

# ------------------------------
# Model
# ------------------------------
write_file "app/Models/SchedulePublication.php" <<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulePublication extends Model
{
    protected $fillable = [
        'term_id',
        'version',
        'notes',
        'published_at',
        'published_by_user_id',
        'storage_base_path',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
PHP

# ------------------------------
# Controllers
# ------------------------------
write_file "app/Http/Controllers/Aop/Schedule/SchedulePublishController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Room;
use App\Models\SchedulePublication;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class SchedulePublishController extends Controller
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

        $publications = collect();
        $latest = null;

        if ($term) {
            $publications = SchedulePublication::where('term_id', $term->id)
                ->orderByDesc('version')
                ->get();
            $latest = $publications->first();
        }

        return view('aop.schedule.publish.index', [
            'term' => $term,
            'publications' => $publications,
            'latest' => $latest,
        ]);
    }

    public function store(Request $request)
    {
        $term = $this->activeTermOrFail();

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $nextVersion = (int) (SchedulePublication::where('term_id', $term->id)->max('version') ?? 0) + 1;
        $base = sprintf('aop/published/%s/v%d', $term->code, $nextVersion);

        // Gather schedule data
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

        $officeBlocks = OfficeHourBlock::query()
            ->where('term_id', $term->id)
            ->orderBy('starts_at')
            ->get();

        // Write files
        $this->ensureDir($base);
        $this->ensureDir($base . '/instructors');
        $this->ensureDir($base . '/rooms');

        // Term schedule CSV (classes only)
        $termCsv = $this->buildTermCsv($term, $meetingBlocks);
        Storage::disk('local')->put($base . '/term_schedule.csv', $termCsv);

        // Instructors (classes + office hours)
        $instructors = Instructor::where('is_active', true)->orderBy('name')->get();
        foreach ($instructors as $ins) {
            $insCsv = $this->buildInstructorCsv($term, $ins, $meetingBlocks, $officeBlocks);
            if ($this->isCsvOnlyHeader($insCsv)) {
                continue;
            }
            Storage::disk('local')->put($base . '/instructors/' . $this->safeSlug($ins->name) . '.csv', $insCsv);
        }

        // Rooms (classes only)
        $rooms = Room::where('is_active', true)->orderBy('name')->get();
        foreach ($rooms as $room) {
            $roomCsv = $this->buildRoomCsv($term, $room, $meetingBlocks);
            if ($this->isCsvOnlyHeader($roomCsv)) {
                continue;
            }
            Storage::disk('local')->put($base . '/rooms/' . $this->safeSlug($room->name) . '.csv', $roomCsv);
        }

        // Create zip bundles
        $this->createZipFromDir($base . '/instructors', $base . '/instructors.zip');
        $this->createZipFromDir($base . '/rooms', $base . '/rooms.zip');

        // Persist publication record
        SchedulePublication::create([
            'term_id' => $term->id,
            'version' => $nextVersion,
            'notes' => $data['notes'] ?? null,
            'published_at' => now(),
            'published_by_user_id' => auth()->id(),
            'storage_base_path' => $base,
        ]);

        return redirect()
            ->route('aop.schedule.publish.index')
            ->with('status', "Published schedule snapshot v{$nextVersion} for {$term->code}.");
    }

    public function downloadTerm(SchedulePublication $publication): StreamedResponse
    {
        $this->assertAdmin($publication);
        return $this->downloadLocalFile($publication->storage_base_path . '/term_schedule.csv', $this->termFileName($publication, 'term_schedule.csv'));
    }

    public function downloadInstructorsZip(SchedulePublication $publication): StreamedResponse
    {
        $this->assertAdmin($publication);
        return $this->downloadLocalFile($publication->storage_base_path . '/instructors.zip', $this->termFileName($publication, 'instructors.zip'));
    }

    public function downloadRoomsZip(SchedulePublication $publication): StreamedResponse
    {
        $this->assertAdmin($publication);
        return $this->downloadLocalFile($publication->storage_base_path . '/rooms.zip', $this->termFileName($publication, 'rooms.zip'));
    }

    private function assertAdmin(SchedulePublication $publication): void
    {
        abort_unless(auth()->check() && auth()->user()->is_admin, 403);
        abort_unless($publication->term_id !== null, 404);
    }

    private function downloadLocalFile(string $storagePath, string $downloadName): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($storagePath), 404, 'File not found.');

        return response()->streamDownload(function () use ($storagePath) {
            $stream = Storage::disk('local')->readStream($storagePath);
            if (!$stream) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        }, $downloadName);
    }

    private function termFileName(SchedulePublication $publication, string $baseName): string
    {
        $term = $publication->term;
        $code = $term?->code ?? 'term';
        return sprintf('aop_%s_v%d_%s', $code, $publication->version, $baseName);
    }

    private function ensureDir(string $storagePath): void
    {
        if (!Storage::disk('local')->exists($storagePath)) {
            Storage::disk('local')->makeDirectory($storagePath);
        }
    }

    private function createZipFromDir(string $dirPath, string $zipPath): void
    {
        $disk = Storage::disk('local');
        if (!$disk->exists($dirPath)) {
            return;
        }

        $fullZipPath = storage_path('app/' . $zipPath);

        if (file_exists($fullZipPath)) {
            @unlink($fullZipPath);
        }

        $zip = new ZipArchive();
        $ok = $zip->open($fullZipPath, ZipArchive::CREATE);
        if ($ok !== true) {
            return;
        }

        $files = $disk->allFiles($dirPath);
        foreach ($files as $file) {
            $localName = str_replace($dirPath . '/', '', $file);
            $zip->addFile(storage_path('app/' . $file), $localName);
        }

        $zip->close();
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

    private function csvWithBom(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv === false ? '' : $csv;
    }

    private function isCsvOnlyHeader(string $csv): bool
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        return is_array($lines) && count(array_filter($lines, fn($l) => trim($l) !== '')) <= 1;
    }

    private function buildTermCsv(Term $term, $meetingBlocks): string
    {
        $rows = [];
        $rows[] = ['Term', 'Course Code', 'Section Code', 'Instructor', 'Room', 'Meeting Type', 'Days', 'Start', 'End', 'Notes'];

        foreach ($meetingBlocks as $mb) {
            $course = $mb->section->offering->catalogCourse;
            $rows[] = [
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
            ];
        }

        return $this->csvWithBom($rows);
    }

    private function buildInstructorCsv(Term $term, Instructor $instructor, $meetingBlocks, $officeBlocks): string
    {
        $rows = [];
        $rows[] = ['Term', 'Instructor', 'Event Kind', 'Course Code', 'Section Code', 'Meeting Type', 'Days', 'Start', 'End', 'Room', 'Notes'];

        foreach ($meetingBlocks as $mb) {
            if (($mb->section->instructor_id ?? null) !== $instructor->id) {
                continue;
            }
            $course = $mb->section->offering->catalogCourse;
            $rows[] = [
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
            ];
        }

        foreach ($officeBlocks as $ob) {
            if (($ob->instructor_id ?? null) !== $instructor->id) {
                continue;
            }
            $rows[] = [
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
            ];
        }

        return $this->csvWithBom($rows);
    }

    private function buildRoomCsv(Term $term, Room $room, $meetingBlocks): string
    {
        $rows = [];
        $rows[] = ['Term', 'Room', 'Course Code', 'Section Code', 'Instructor', 'Meeting Type', 'Days', 'Start', 'End', 'Notes'];

        foreach ($meetingBlocks as $mb) {
            if (($mb->room_id ?? null) !== $room->id) {
                continue;
            }
            $course = $mb->section->offering->catalogCourse;
            $rows[] = [
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
            ];
        }

        return $this->csvWithBom($rows);
    }
}
PHP

# ScheduleReportsController (includes Phase 7.1 fix)
write_file "app/Http/Controllers/Aop/Schedule/ScheduleReportsController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Room;
use App\Models\SchedulePublication;
use App\Models\Section;
use App\Models\Term;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $latestPublication = null;
        if ($term) {
            $latestPublication = SchedulePublication::where('term_id', $term->id)->orderByDesc('version')->first();
        }

        if (!$term) {
            return view('aop.schedule.reports.index', [
                'term' => null,
                'instructors' => $instructors,
                'rooms' => $rooms,
                'stats' => null,
                'unassigned' => null,
                'latestPublication' => null,
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
            'latestPublication' => $latestPublication,
        ]);
    }

    public function exportTerm(): StreamedResponse
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

    public function exportInstructor(Instructor $instructor): StreamedResponse
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

    public function exportRoom(Room $room): StreamedResponse
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
PHP

# Schedule home controller
write_file "app/Http/Controllers/Aop/Schedule/ScheduleHomeController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Http\Controllers\Controller;
use App\Models\SchedulePublication;
use App\Models\Term;

class ScheduleHomeController extends Controller
{
    public function index()
    {
        $term = Term::where('is_active', true)->first();

        $latestPublication = null;
        if ($term) {
            $latestPublication = SchedulePublication::where('term_id', $term->id)->orderByDesc('version')->first();
        }

        return view('aop.schedule.index', [
            'term' => $term,
            'latestPublication' => $latestPublication,
        ]);
    }
}
PHP

# ------------------------------
# Views
# ------------------------------
write_file "resources/views/aop/schedule/publish/index.blade.php" <<'BLADE'
<x-aop-layout>
  <x-slot:title>Publish Snapshots</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Publish Snapshots</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
        @if($latest)
          <p class="muted">Latest published: <span class="badge">v{{ $latest->version }}</span> {{ $latest->published_at->format('Y-m-d H:i') }}</p>
        @else
          <p class="muted">Latest published: <span class="badge">None</span></p>
        @endif
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
      <a class="btn" href="{{ route('aop.schedule.reports.index') }}">Schedule Reports</a>
      @if(!$term)
        <a class="btn" href="{{ route('aop.terms.index') }}">Go to Terms</a>
      @endif
    </div>
  </div>

  @if(!$term)
    <div class="card">
      <h2>No Active Term</h2>
      <p>You must set an active term before publishing schedule snapshots.</p>
    </div>
  @else
    <div class="card">
      <h2>Publish a New Snapshot</h2>
      <p class="muted">Publishing captures CSV exports and zip bundles at a point in time. This does not change your live schedule.</p>

      <form method="POST" action="{{ route('aop.schedule.publish.store') }}" style="margin-top:10px;">
        @csrf
        <label>Notes (optional)</label>
        <textarea name="notes" placeholder="e.g., Sent to Dean for review; labs still TBD.">{{ old('notes') }}</textarea>
        <div class="actions" style="margin-top:10px;">
          <button class="btn" type="submit">Publish Snapshot</button>
        </div>
      </form>
    </div>

    <div style="height:14px;"></div>

    <div class="card">
      <h2>Published Versions</h2>
      @if($publications->count() === 0)
        <p class="muted">No snapshots have been published for this term yet.</p>
      @else
        <table style="margin-top:8px;">
          <thead>
            <tr>
              <th style="width:90px;">Version</th>
              <th style="width:170px;">Published</th>
              <th style="width:180px;">By</th>
              <th>Notes</th>
              <th style="width:260px;">Downloads</th>
            </tr>
          </thead>
          <tbody>
            @foreach($publications as $p)
              <tr>
                <td><span class="badge">v{{ $p->version }}</span></td>
                <td>{{ $p->published_at->format('Y-m-d H:i') }}</td>
                <td>{{ $p->publishedBy?->name ?? 'Unknown' }}</td>
                <td class="muted">{{ $p->notes ?? '' }}</td>
                <td>
                  <div class="actions" style="gap:8px;">
                    <a class="btn" href="{{ route('aop.schedule.publish.downloadTerm', $p) }}">Term CSV</a>
                    <a class="btn secondary" href="{{ route('aop.schedule.publish.downloadInstructorsZip', $p) }}">Instructors ZIP</a>
                    <a class="btn secondary" href="{{ route('aop.schedule.publish.downloadRoomsZip', $p) }}">Rooms ZIP</a>
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

# Schedule home view
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
      </div>
    @endif
  </div>
</x-aop-layout>
BLADE

# Reports view update (adds published stamp and link)
write_file "resources/views/aop/schedule/reports/index.blade.php" <<'BLADE'
<x-aop-layout>
  <x-slot:title>Schedule Reports</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Schedule Reports</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
        @if($latestPublication)
          <p class="muted">Published: <span class="badge">v{{ $latestPublication->version }}</span> {{ $latestPublication->published_at->format('Y-m-d H:i') }}</p>
        @else
          <p class="muted">Published: <span class="badge">None</span></p>
        @endif
        <p class="muted">Exports are scoped to the active term.</p>
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
      @if($term)
        <a class="btn" href="{{ route('aop.schedule.publish.index') }}">Publish Snapshots</a>
      @endif
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
BLADE

# ------------------------------
# Routes
# ------------------------------
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
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
PHP

# ------------------------------
# Permissions
# ------------------------------
chmod 755 "$ROOT_DIR/phase-8-publish-snapshots.sh"

# Keep php files world-readable
find "$ROOT_DIR/app" "$ROOT_DIR/routes" "$ROOT_DIR/resources" "$ROOT_DIR/database" -type d -exec chmod 755 {} \; || true
find "$ROOT_DIR/app" "$ROOT_DIR/routes" "$ROOT_DIR/resources" "$ROOT_DIR/database" -type f -exec chmod 644 {} \; || true

echo "OK: Phase 8 applied (publish snapshots + version stamp + Phase 7.1 fix)."

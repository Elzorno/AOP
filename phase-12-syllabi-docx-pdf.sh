#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Phase 12: Syllabi DOCX/PDF rendering (LibreOffice headless)
# Host requirement:
#   apt-get update && apt-get install -y libreoffice

mkdir -p "$ROOT_DIR/app/Services"
mkdir -p "$ROOT_DIR/app/Http/Controllers/Aop/Syllabi"
mkdir -p "$ROOT_DIR/resources/views/aop/syllabi"

cat > "$ROOT_DIR/app/Services/SyllabusRenderService.php" <<'PHP'
<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class SyllabusRenderService
{
    /**
     * Render HTML to DOCX or PDF using LibreOffice (soffice) headless conversion.
     *
     * Host requirement:
     *  - Install LibreOffice in the LXC (package: libreoffice)
     *  - `soffice` must be available on PATH
     */
    public function renderHtmlTo(string $html, string $format, string $outDir, string $baseName): string
    {
        $format = strtolower(trim($format));
        if (!in_array($format, ['docx', 'pdf'], true)) {
            throw new \InvalidArgumentException('Unsupported format: ' . $format);
        }

        if (!is_dir($outDir) && !@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            throw new \RuntimeException('Unable to create output directory: ' . $outDir);
        }

        $soffice = $this->findSoffice();
        if ($soffice === null) {
            throw new \RuntimeException('LibreOffice is not installed or `soffice` is not available. Install `libreoffice` in the LXC.');
        }

        $tmpDir = rtrim(sys_get_temp_dir(), '/') . '/aop_syllabi_' . bin2hex(random_bytes(6));
        if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Unable to create temp directory.');
        }

        $htmlPath = $tmpDir . '/' . $baseName . '.html';
        file_put_contents($htmlPath, $html);

        $process = new Process([
            $soffice,
            '--headless',
            '--nologo',
            '--nolockcheck',
            '--nodefault',
            '--norestore',
            '--convert-to',
            $format,
            '--outdir',
            $outDir,
            $htmlPath,
        ]);
        $process->setTimeout(120);
        $process->run();

        @unlink($htmlPath);
        @rmdir($tmpDir);

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException('LibreOffice conversion failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        $outPath = rtrim($outDir, '/') . '/' . $baseName . '.' . $format;
        if (!is_file($outPath)) {
            $candidates = glob(rtrim($outDir, '/') . '/*.' . $format) ?: [];
            if ($candidates) {
                usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
                $outPath = $candidates[0];
            }
        }

        if (!is_file($outPath)) {
            throw new \RuntimeException('Converted file not found after LibreOffice conversion.');
        }

        return $outPath;
    }

    private function findSoffice(): ?string
    {
        foreach (['/usr/bin/soffice', '/usr/local/bin/soffice'] as $p) {
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }

        $proc = new Process(['bash', '-lc', 'command -v soffice']);
        $proc->setTimeout(5);
        $proc->run();
        if ($proc->isSuccessful()) {
            $path = trim($proc->getOutput());
            if ($path !== '' && is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
PHP

cat > "$ROOT_DIR/app/Http/Controllers/Aop/Syllabi/SyllabiController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Syllabi;

use App\Enums\MeetingBlockType;
use App\Http\Controllers\Controller;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\SchedulePublication;
use App\Models\Section;
use App\Models\Term;
use App\Services\SyllabusRenderService;
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
        $html = $this->renderSyllabusHtml($term, $section, $data);

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

    public function downloadDocx(Section $section): StreamedResponse
    {
        return $this->downloadRendered($section, 'docx');
    }

    public function downloadPdf(Section $section): StreamedResponse
    {
        return $this->downloadRendered($section, 'pdf');
    }

    private function downloadRendered(Section $section, string $format): StreamedResponse
    {
        $term = $this->activeTermOrFail();
        abort_unless($section->offering && $section->offering->term_id === $term->id, 404);

        $data = $this->buildSyllabusDataFromSection($term, $section);
        $html = $this->renderSyllabusHtml($term, $section, $data);

        $baseName = sprintf('syllabus_%s_%s_%s', $term->code, $data['course_code'], $this->safeSlug($section->section_code));
        $outDir = storage_path('app/aop/syllabi/_render_tmp');

        /** @var SyllabusRenderService $renderer */
        $renderer = app(SyllabusRenderService::class);

        try {
            $outPath = $renderer->renderHtmlTo($html, $format, $outDir, $baseName);
        } catch (\Throwable $e) {
            abort(500, $e->getMessage());
        }

        $filename = $baseName . '.' . $format;

        return response()->streamDownload(function () use ($outPath) {
            $fh = fopen($outPath, 'rb');
            if ($fh) {
                fpassthru($fh);
                fclose($fh);
            }
            @unlink($outPath);
        }, $filename, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
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

        $renderer = app(SyllabusRenderService::class);
        $renderOutDir = storage_path('app/' . $base . '/_render');
        if (!is_dir($renderOutDir)) {
            @mkdir($renderOutDir, 0755, true);
        }

        foreach ($sections as $section) {
            $data = $this->buildSyllabusDataFromSection($term, $section);
            $html = $this->renderSyllabusHtml($term, $section, $data);

            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = "{}";
            }

            $stub = sprintf('%s_%s', $data['course_code'], $this->safeSlug($section->section_code));
            $disk->put($base . '/' . $stub . '.html', $html);
            $disk->put($base . '/' . $stub . '.json', $json);

            try {
                $docxPath = $renderer->renderHtmlTo($html, 'docx', $renderOutDir, $stub);
                $pdfPath = $renderer->renderHtmlTo($html, 'pdf', $renderOutDir, $stub);

                $disk->put($base . '/' . $stub . '.docx', file_get_contents($docxPath) ?: '');
                $disk->put($base . '/' . $stub . '.pdf', file_get_contents($pdfPath) ?: '');

                @unlink($docxPath);
                @unlink($pdfPath);
            } catch (\Throwable $e) {
                abort(500, 'Syllabus rendering failed. Ensure LibreOffice is installed in the LXC. Details: ' . $e->getMessage());
            }
        }

        $zipStoragePath = $base . '/syllabi_bundle.zip';
        $this->createZipFromDir($base, $zipStoragePath);

        $downloadName = sprintf('aop_%s_v%d_syllabi_html_json_docx_pdf.zip', $term->code, $publication->version);

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
            if (str_contains($file, '/_render/')) {
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

    private function renderSyllabusHtml(Term $term, Section $section, array $data): string
    {
        return view('aop.syllabi.render', [
            'term' => $term,
            'section' => $section,
            'syllabus' => $data,
        ])->render();
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

cat > "$ROOT_DIR/resources/views/aop/syllabi/index.blade.php" <<'BLADE'
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
      <h2>Syllabus Bundle (Published Snapshot)</h2>
      <p class="muted">
        Generates a ZIP containing HTML + JSON + DOCX + PDF syllabi for all sections in the active term.
        DOCX/PDF rendering requires LibreOffice installed in the LXC.
      </p>

      @if($latestPublication)
        <form method="POST" action="{{ route('aop.syllabi.bundle', $latestPublication) }}" style="margin-top:10px;">
          @csrf
          <button class="btn" type="submit">Generate ZIP for Published v{{ $latestPublication->version }}</button>
        </form>
        <p class="muted" style="margin-top:10px; font-size:12px;">
          If generation fails, install LibreOffice: <code>apt-get update && apt-get install -y libreoffice</code>
        </p>
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
              <th style="width:420px;">Actions</th>
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
                    <a class="btn secondary" href="{{ route('aop.syllabi.downloadDocx', $s) }}">DOCX</a>
                    <a class="btn secondary" href="{{ route('aop.syllabi.downloadPdf', $s) }}">PDF</a>
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

cat > "$ROOT_DIR/resources/views/aop/syllabi/show.blade.php" <<'BLADE'
<x-aop-layout>
  <x-slot:title>Syllabus</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabus</h1>
      <p style="margin-top:6px;"><strong>{{ $syllabus['course_code'] }}</strong> — {{ $syllabus['course_title'] }} ({{ $syllabus['section_code'] }})</p>
      <p class="muted">{{ $term->code }} — {{ $term->name }}</p>
    </div>
    <div class="actions" style="flex-wrap:wrap;">
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadHtml', $section) }}">HTML</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadJson', $section) }}">JSON</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadDocx', $section) }}">DOCX</a>
      <a class="btn secondary" href="{{ route('aop.syllabi.downloadPdf', $section) }}">PDF</a>
      <a class="btn" href="#" onclick="window.open('{{ route('aop.syllabi.show', $section) }}?print=1','_blank'); return false;">Print</a>
    </div>
  </div>

  <div class="card">
    @include('aop.syllabi.partials.syllabus', ['syllabus' => $syllabus])
    <div class="muted" style="margin-top:10px; font-size:12px;">
      DOCX/PDF rendering requires LibreOffice installed in the LXC.
    </div>
  </div>
</x-aop-layout>
BLADE

# Patch routes/web.php by full replacement: we re-write the file content as-is except we ensure docx/pdf routes exist.
# For simplicity, we do an idempotent insert if missing.

if ! grep -q "syllabi.downloadDocx" "$ROOT_DIR/routes/web.php"; then
  tmpfile="$(mktemp)"
  awk 'BEGIN{added=0} {print} /syllabi\\/sections\\/{section}\\/download\\/json/ && added==0 {print "        Route::get(\"/syllabi/sections/{section}/download/docx\", [SyllabiController::class, \"downloadDocx\"])->name(\"syllabi.downloadDocx\");"; print "        Route::get(\"/syllabi/sections/{section}/download/pdf\", [SyllabiController::class, \"downloadPdf\"])->name(\"syllabi.downloadPdf\");"; added=1} ' "$ROOT_DIR/routes/web.php" > "$tmpfile"
  mv "$tmpfile" "$ROOT_DIR/routes/web.php"
fi

# Permissions (keeps things readable; storage/cache writable is handled elsewhere)
chown -R www-data:www-data "$ROOT_DIR/app/Http/Controllers/Aop/Syllabi" "$ROOT_DIR/app/Services" "$ROOT_DIR/resources/views/aop/syllabi" "$ROOT_DIR/routes/web.php" || true
find "$ROOT_DIR/app/Http/Controllers/Aop/Syllabi" -type f -exec chmod 644 {} \;
find "$ROOT_DIR/app/Services" -type f -exec chmod 644 {} \;
find "$ROOT_DIR/resources/views/aop/syllabi" -type f -exec chmod 644 {} \;
chmod 644 "$ROOT_DIR/routes/web.php" || true

echo "OK: Phase 12 applied (Syllabi DOCX/PDF rendering via LibreOffice)."

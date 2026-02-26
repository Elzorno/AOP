#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

write_file() {
  local rel="$1"
  local tmp="$ROOT_DIR/.tmp_write_$$"
  mkdir -p "$(dirname "$ROOT_DIR/$rel")"
  cat > "$tmp"
  mv "$tmp" "$ROOT_DIR/$rel"
  chown www-data:www-data "$ROOT_DIR/$rel" 2>/dev/null || true
  chmod 644 "$ROOT_DIR/$rel" 2>/dev/null || true
}

write_file 'app/Services/SyllabusDataService.php' <<'EOF'
<?php

namespace App\Services;

use App\Models\OfficeHourBlock;
use App\Models\Section;
use App\Models\Term;

class SyllabusDataService
{
    public function buildPacketForSection(Section $section): array
    {
        $section->loadMissing([
            'offering.term',
            'offering.catalogCourse',
            'instructor',
            'meetingBlocks.room',
        ]);

        /** @var Term|null $term */
        $term = $section->offering?->term;

        $course = $section->offering?->catalogCourse;
        $instructor = $section->instructor;

        $officeHours = [];
        if ($term && $instructor) {
            $officeHours = OfficeHourBlock::query()
                ->where('term_id', $term->id)
                ->where('instructor_id', $instructor->id)
                ->orderBy('starts_at')
                ->get()
                ->map(fn ($b) => [
                    'days' => $b->days_json ?? [],
                    'start' => substr((string)$b->starts_at, 0, 5),
                    'end' => substr((string)$b->ends_at, 0, 5),
                    'notes' => $b->notes,
                ])
                ->all();
        }

        $meetingBlocks = $section->meetingBlocks
            ->sortBy('starts_at')
            ->map(fn ($mb) => [
                'type' => is_object($mb->type) && property_exists($mb->type, 'value') ? $mb->type->value : (string)$mb->type,
                'days' => $mb->days_json ?? [],
                'start' => substr((string)$mb->starts_at, 0, 5),
                'end' => substr((string)$mb->ends_at, 0, 5),
                'room' => $mb->room?->name ?? '',
                'notes' => $mb->notes,
            ])
            ->values()
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'term' => [
                'code' => $term?->code ?? '',
                'name' => $term?->name ?? '',
            ],
            'course' => [
                'code' => $course?->code ?? '',
                'title' => $course?->title ?? '',
                'department' => $course?->department ?? '',
                'credits_text' => $course?->credits_text ?? '',
                'credits_min' => $course?->credits_min,
                'credits_max' => $course?->credits_max,
                'contact_hours_per_week' => $course?->contact_hours_per_week,
                'course_lab_fee' => $course?->course_lab_fee,
                'prerequisites' => $course?->prerequisites ?? '',
                'corequisites' => $course?->corequisites ?? '',
                'description' => $course?->description ?? '',
                'notes' => $course?->notes ?? '',
            ],
            'section' => [
                'code' => $section->section_code,
                'modality' => $section->modality,
                'notes' => $section->notes,
            ],
            'instructor' => [
                'name' => $instructor?->name ?? '',
                'email' => $instructor?->email ?? '',
            ],
            'office_hours' => $officeHours,
            'meeting_blocks' => $meetingBlocks,
        ];
    }

    public function formatOfficeHoursLine(array $officeHours): string
    {
        if (count($officeHours) === 0) {
            return 'TBD';
        }

        $chunks = [];
        foreach ($officeHours as $b) {
            $days = $this->daysToString($b['days'] ?? []);
            $start = $b['start'] ?? '';
            $end = $b['end'] ?? '';
            $label = trim($days . ' ' . $start . '-' . $end);
            if (!empty($b['notes'])) {
                $label .= ' (' . $b['notes'] . ')';
            }
            if ($label !== '') {
                $chunks[] = $label;
            }
        }

        return $chunks ? implode('; ', $chunks) : 'TBD';
    }

    public function formatMeetingInfo(array $meetingBlocks): array
    {
        if (count($meetingBlocks) === 0) {
            return [
                'days' => 'TBD',
                'time' => 'TBD',
                'location' => 'TBD',
                'delivery_mode' => 'TBD',
            ];
        }

        // Use the first block as the "primary" meeting info.
        $mb = $meetingBlocks[0];
        $days = $this->daysToString($mb['days'] ?? []);
        $time = trim(($mb['start'] ?? '') . '-' . ($mb['end'] ?? ''));
        $room = $mb['room'] ?? '';

        return [
            'days' => $days !== '' ? $days : 'TBD',
            'time' => $time !== '-' ? $time : 'TBD',
            'location' => $room !== '' ? $room : 'TBD',
            'delivery_mode' => 'TBD',
        ];
    }

    private function daysToString(array $days): string
    {
        $order = ['Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6,'Sun'=>7];
        $days = array_values(array_filter($days, fn($d) => is_string($d) && $d !== ''));
        usort($days, fn($a,$b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        return implode(', ', $days);
    }
}
EOF

write_file 'app/Services/DocxTemplateService.php' <<'EOF'
<?php

namespace App\Services;

use ZipArchive;

class DocxTemplateService
{
    /**
     * Render a DOCX by replacing {{TOKENS}} inside the template XML parts.
     *
     * IMPORTANT: Placeholders must exist as contiguous text in the DOCX (single run).
     */
    public function render(string $templatePath, array $replacements, string $outputPath): void
    {
        if (!is_file($templatePath)) {
            throw new \RuntimeException('Template not found: ' . $templatePath);
        }

        $tmp = sys_get_temp_dir() . '/aop_docx_' . bin2hex(random_bytes(6));
        if (!@mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            throw new \RuntimeException('Unable to create temp directory.');
        }

        $workDocx = $tmp . '/template.docx';
        copy($templatePath, $workDocx);

        $zip = new ZipArchive();
        if ($zip->open($workDocx) !== true) {
            throw new \RuntimeException('Unable to open DOCX template as ZIP.');
        }

        // Escape replacements for XML.
        $safe = [];
        foreach ($replacements as $k => $v) {
            $safe[$k] = $this->xmlEscape((string)$v);
        }

        // Replace tokens in all word/*.xml parts.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'] ?? '';
            if (!str_starts_with($name, 'word/') || !str_ends_with($name, '.xml')) {
                continue;
            }

            $xml = $zip->getFromIndex($i);
            if ($xml === false) {
                continue;
            }

            $updated = $xml;
            foreach ($safe as $token => $value) {
                $updated = str_replace('{{' . $token . '}}', $value, $updated);
            }

            if ($updated !== $xml) {
                $zip->deleteName($name);
                $zip->addFromString($name, $updated);
            }
        }

        $zip->close();

        // Ensure output dir
        $outDir = dirname($outputPath);
        if (!is_dir($outDir) && !@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            throw new \RuntimeException('Unable to create output directory: ' . $outDir);
        }

        copy($workDocx, $outputPath);

        // cleanup
        @unlink($workDocx);
        @rmdir($tmp);
    }

    private function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
EOF

write_file 'app/Services/DocxPdfConvertService.php' <<'EOF'
<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class DocxPdfConvertService
{
    public function docxToPdf(string $docxPath, string $outDir, string $baseName): string
    {
        if (!is_file($docxPath)) {
            throw new \RuntimeException('DOCX not found: ' . $docxPath);
        }

        if (!is_dir($outDir) && !@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            throw new \RuntimeException('Unable to create output directory: ' . $outDir);
        }

        $soffice = $this->findBinary(['/usr/bin/soffice', '/usr/local/bin/soffice', 'soffice']);
        if ($soffice === null) {
            throw new \RuntimeException('LibreOffice (soffice) not found. Install: apt-get install -y libreoffice');
        }

        $p = new Process([
            $soffice,
            '--headless',
            '--nologo',
            '--nolockcheck',
            '--nodefault',
            '--norestore',
            '--convert-to',
            'pdf',
            '--outdir',
            $outDir,
            $docxPath,
        ]);
        $p->setTimeout(180);
        $p->run();

        if (!$p->isSuccessful()) {
            $err = trim($p->getErrorOutput() ?: $p->getOutput());
            throw new \RuntimeException('LibreOffice PDF conversion failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        $expected = rtrim($outDir, '/') . '/' . $baseName . '.pdf';
        if (is_file($expected)) {
            return $expected;
        }

        // LO may use original name
        $candidates = glob(rtrim($outDir, '/') . '/*.pdf') ?: [];
        if ($candidates) {
            usort($candidates, fn($a,$b) => filemtime($b) <=> filemtime($a));
            return $candidates[0];
        }

        throw new \RuntimeException('PDF not found after conversion.');
    }

    private function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (is_string($c) && str_starts_with($c, '/')) {
                if (is_file($c) && is_executable($c)) {
                    return $c;
                }
                continue;
            }
            $proc = new Process(['bash', '-lc', 'command -v ' . escapeshellarg((string)$c) . ' 2>/dev/null || true']);
            $proc->setTimeout(5);
            $proc->run();
            $path = trim($proc->getOutput());
            if ($path !== '' && is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }
}
EOF

write_file 'app/Http/Controllers/Aop/SyllabusController.php' <<'EOF'
<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Term;
use App\Services\DocxPdfConvertService;
use App\Services\DocxTemplateService;
use App\Services\SyllabusDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SyllabusController extends Controller
{
    public function index()
    {
        $term = Term::where('is_active', true)->first();

        $sections = collect();
        if ($term) {
            $sections = Section::query()
                ->with(['offering.catalogCourse','instructor'])
                ->whereHas('offering', fn($q) => $q->where('term_id', $term->id))
                ->orderBy('id')
                ->get();
        }

        $templateExists = Storage::disk('local')->exists('aop/syllabi/templates/default.docx');

        return view('aop.syllabi.index', [
            'term' => $term,
            'sections' => $sections,
            'templateExists' => $templateExists,
        ]);
    }

    public function uploadTemplate(Request $request)
    {
        $request->validate([
            'template' => ['required','file','mimes:docx','max:10240'],
        ]);

        $file = $request->file('template');
        $path = 'aop/syllabi/templates/default.docx';
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        return redirect()->route('aop.syllabi.index')->with('status', 'Template uploaded.');
    }

    public function show(Section $section, SyllabusDataService $data)
    {
        $packet = $data->buildPacketForSection($section);
        $html = $this->renderHtmlPreview($packet, $data);

        return view('aop.syllabi.show', [
            'section' => $section,
            'packet' => $packet,
            'html' => $html,
        ]);
    }

    public function downloadJson(Section $section, SyllabusDataService $data): StreamedResponse
    {
        $packet = $data->buildPacketForSection($section);
        $name = $this->fileBase($packet) . '.json';

        return response()->streamDownload(function () use ($packet) {
            echo json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $name, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function downloadHtml(Section $section, SyllabusDataService $data): StreamedResponse
    {
        $packet = $data->buildPacketForSection($section);
        $html = $this->renderHtmlPreview($packet, $data);
        $name = $this->fileBase($packet) . '.html';

        return response()->streamDownload(function () use ($html) {
            echo $html;
        }, $name, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function downloadDocx(
        Section $section,
        SyllabusDataService $data,
        DocxTemplateService $docx
    ): StreamedResponse {
        $packet = $data->buildPacketForSection($section);
        $base = $this->fileBase($packet);

        $templatePath = storage_path('app/aop/syllabi/templates/default.docx');
        if (!is_file($templatePath)) {
            abort(500, 'Syllabus template not found. Upload a template on the Syllabi page.');
        }

        $outDir = storage_path('app/aop/syllabi/generated');
        $outPath = $outDir . '/' . $base . '.docx';

        $repl = $this->buildReplacements($packet, $data);
        $docx->render($templatePath, $repl, $outPath);

        return response()->streamDownload(function () use ($outPath) {
            $fh = fopen($outPath, 'rb');
            fpassthru($fh);
            fclose($fh);
        }, basename($outPath), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    public function downloadPdf(
        Section $section,
        SyllabusDataService $data,
        DocxTemplateService $docx,
        DocxPdfConvertService $pdf
    ): StreamedResponse {
        $packet = $data->buildPacketForSection($section);
        $base = $this->fileBase($packet);

        $templatePath = storage_path('app/aop/syllabi/templates/default.docx');
        if (!is_file($templatePath)) {
            abort(500, 'Syllabus template not found. Upload a template on the Syllabi page.');
        }

        $outDir = storage_path('app/aop/syllabi/generated');
        $docxPath = $outDir . '/' . $base . '.docx';
        $pdfPath = $outDir . '/' . $base . '.pdf';

        $repl = $this->buildReplacements($packet, $data);
        $docx->render($templatePath, $repl, $docxPath);

        $actualPdf = $pdf->docxToPdf($docxPath, $outDir, $base);
        if ($actualPdf !== $pdfPath) {
            // normalize name
            @copy($actualPdf, $pdfPath);
        }

        return response()->streamDownload(function () use ($pdfPath) {
            $fh = fopen($pdfPath, 'rb');
            fpassthru($fh);
            fclose($fh);
        }, basename($pdfPath), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function renderHtmlPreview(array $packet, SyllabusDataService $data): string
    {
        $repl = $this->buildReplacements($packet, $data);

        $escape = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html><head><meta charset="utf-8"><title>Syllabus</title>'
            . '<style>body{font-family:Arial,Helvetica,sans-serif;max-width:850px;margin:24px auto;line-height:1.35} h1{margin-bottom:0} .muted{color:#666} .box{border:1px solid #ddd;padding:12px;border-radius:10px;margin:12px 0} table{width:100%;border-collapse:collapse} td{padding:6px 8px;vertical-align:top;border-bottom:1px solid #eee}</style>'
            . '</head><body>'
            . '<h1>' . $escape($repl['COURSE_CODE'] . ' - ' . $repl['COURSE_TITLE']) . '</h1>'
            . '<div class="muted">' . $escape($repl['DEPARTMENT_LINE']) . '</div>'
            . '<div class="muted">' . $escape($repl['INSTRUCTOR_NAME']) . ' — ' . $escape($repl['INSTRUCTOR_EMAIL']) . '</div>'
            . '<div class="muted">' . $escape($repl['SYLLABUS_DATE']) . '</div>'
            . '<h2>Meeting Information</h2>'
            . '<div class="box"><table>'
            . '<tr><td><strong>Credit Hours</strong></td><td>' . $escape($repl['CREDIT_HOURS']) . '</td></tr>'
            . '<tr><td><strong>Delivery Mode</strong></td><td>' . $escape($repl['DELIVERY_MODE']) . '</td></tr>'
            . '<tr><td><strong>Location</strong></td><td>' . $escape($repl['LOCATION']) . '</td></tr>'
            . '<tr><td><strong>Meeting Days</strong></td><td>' . $escape($repl['MEETING_DAYS']) . '</td></tr>'
            . '<tr><td><strong>Meeting Time</strong></td><td>' . $escape($repl['MEETING_TIME']) . '</td></tr>'
            . '<tr><td><strong>Corequisites</strong></td><td>' . $escape($repl['COREQUISITES']) . '</td></tr>'
            . '<tr><td><strong>Office Hours</strong></td><td>' . $escape($repl['OFFICE_HOURS']) . '</td></tr>'
            . '</table></div>'
            . '<h2>Course Description</h2><p>' . nl2br($escape($repl['COURSE_DESCRIPTION'])) . '</p>'
            . '<h2>Course Objectives</h2><p>' . nl2br($escape($repl['COURSE_OBJECTIVES'])) . '</p>'
            . '<h2>Required Materials</h2><p>' . nl2br($escape($repl['REQUIRED_MATERIALS'])) . '</p>'
            . '<p class="muted">Template-driven DOCX/PDF output is available via the download buttons.</p>'
            . '</body></html>';
    }

    private function buildReplacements(array $packet, SyllabusDataService $data): array
    {
        $meeting = $data->formatMeetingInfo($packet['meeting_blocks'] ?? []);

        $credits = $packet['course']['credits_text'] ?? '';
        if ($credits === '' && ($packet['course']['credits_min'] ?? null) !== null) {
            $min = $packet['course']['credits_min'];
            $max = $packet['course']['credits_max'];
            $credits = ($max !== null && $max != $min) ? ($min . '-' . $max) : (string)$min;
        }

        return [
            'COURSE_CODE' => $packet['course']['code'] ?? '',
            'COURSE_TITLE' => $packet['course']['title'] ?? '',
            'DEPARTMENT_LINE' => ($packet['course']['department'] ?? '') !== ''
                ? ($packet['course']['department'])
                : 'Department of Engineering Technologies – Information Security/Cyber Security',

            'INSTRUCTOR_NAME' => $packet['instructor']['name'] ?? '',
            'INSTRUCTOR_EMAIL' => $packet['instructor']['email'] ?? '',

            'SYLLABUS_DATE' => now()->toDateString(),

            'CREDIT_HOURS' => $credits !== '' ? $credits : 'TBD',
            'DELIVERY_MODE' => $meeting['delivery_mode'] ?? 'TBD',
            'LOCATION' => $meeting['location'] ?? 'TBD',
            'MEETING_DAYS' => $meeting['days'] ?? 'TBD',
            'MEETING_TIME' => $meeting['time'] ?? 'TBD',

            'COREQUISITES' => ($packet['course']['corequisites'] ?? '') !== '' ? $packet['course']['corequisites'] : 'none',
            'OFFICE_HOURS' => $data->formatOfficeHoursLine($packet['office_hours'] ?? []),

            'COURSE_DESCRIPTION' => ($packet['course']['description'] ?? '') !== '' ? $packet['course']['description'] : 'TBD',
            'COURSE_OBJECTIVES' => 'TBD',
            'REQUIRED_MATERIALS' => 'TBD',
        ];
    }

    private function fileBase(array $packet): string
    {
        $term = $packet['term']['code'] ?? 'TERM';
        $code = $packet['course']['code'] ?? 'COURSE';
        $sec = $packet['section']['code'] ?? '00';
        $term = preg_replace('/[^A-Za-z0-9]+/', '', $term) ?? $term;
        $code = preg_replace('/[^A-Za-z0-9]+/', '', $code) ?? $code;
        $sec = preg_replace('/[^A-Za-z0-9]+/', '', $sec) ?? $sec;
        return 'syllabus_' . strtolower($term) . '_' . strtolower($code) . '_' . strtolower($sec);
    }
}
EOF

write_file 'resources/views/aop/syllabi/index.blade.php' <<'EOF'
<x-aop-layout>
  <x-slot:title>Syllabi</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabi</h1>
      @if($term)
        <p style="margin-top:6px;"><strong>{{ $term->code }}</strong> — {{ $term->name }}</p>
      @else
        <p class="muted">No active term is set.</p>
      @endif
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.schedule.home') }}">Back to Schedule</a>
    </div>
  </div>

  @if(session('status'))
    <div class="card" style="border-left:4px solid #2ecc71;">
      <strong>{{ session('status') }}</strong>
    </div>
    <div style="height:10px;"></div>
  @endif

  <div class="card">
    <h2>Template</h2>
    <p class="muted">DOCX and PDF are generated from a DOCX template. Upload a new template to change formatting.</p>

    <div style="margin-top:10px; display:flex; gap:12px; align-items:center;">
      <div>
        @if($templateExists)
          <span class="badge">Template: Installed</span>
        @else
          <span class="badge" style="background:#ffe8e8;">Template: Missing</span>
        @endif
      </div>

      <form method="POST" action="{{ route('aop.syllabi.template.upload') }}" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; margin:0;">
        @csrf
        <input type="file" name="template" accept=".docx" required>
        <button class="btn" type="submit">Upload Template</button>
      </form>
    </div>

    @error('template')
      <div class="muted" style="margin-top:8px; color:#b00020;">{{ $message }}</div>
    @enderror

    <div class="muted" style="margin-top:10px; font-size:12px;">
      Tip: Install LibreOffice in the LXC for PDF conversion: <code>apt-get install -y libreoffice</code>
    </div>
  </div>

  <div style="height:14px;"></div>

  <div class="card">
    <h2>Sections</h2>

    @if(!$term)
      <p class="muted">Set an active term to generate syllabi.</p>
    @elseif($sections->count() === 0)
      <p class="muted">No sections found for the active term.</p>
    @else
      <table style="margin-top:8px;">
        <thead>
          <tr>
            <th>Course</th>
            <th>Section</th>
            <th>Instructor</th>
            <th style="width:360px;">Outputs</th>
          </tr>
        </thead>
        <tbody>
          @foreach($sections as $s)
            <tr>
              <td>
                <strong>{{ $s->offering->catalogCourse->code }}</strong>
                <div class="muted">{{ $s->offering->catalogCourse->title }}</div>
              </td>
              <td>
                <span class="badge">{{ $s->section_code }}</span>
                <div class="muted">{{ $s->modality }}</div>
              </td>
              <td>
                {{ $s->instructor?->name ?? 'TBD' }}
                <div class="muted">{{ $s->instructor?->email ?? '' }}</div>
              </td>
              <td>
                <div class="actions" style="gap:8px; flex-wrap:wrap;">
                  <a class="btn secondary" href="{{ route('aop.syllabi.show', $s) }}">View</a>
                  <a class="btn secondary" href="{{ route('aop.syllabi.downloadHtml', $s) }}">HTML</a>
                  <a class="btn secondary" href="{{ route('aop.syllabi.downloadJson', $s) }}">JSON</a>
                  <a class="btn" href="{{ route('aop.syllabi.downloadDocx', $s) }}">DOCX</a>
                  <a class="btn" href="{{ route('aop.syllabi.downloadPdf', $s) }}">PDF</a>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</x-aop-layout>
EOF

write_file 'resources/views/aop/syllabi/show.blade.php' <<'EOF'
<x-aop-layout>
  <x-slot:title>Syllabus Preview</x-slot:title>

  <div class="row" style="margin-bottom:14px;">
    <div>
      <h1>Syllabus Preview</h1>
      <p class="muted" style="margin-top:6px;">
        {{ $packet['course']['code'] ?? '' }} — {{ $packet['course']['title'] ?? '' }} (Section {{ $packet['section']['code'] ?? '' }})
      </p>
    </div>
    <div class="actions">
      <a class="btn secondary" href="{{ route('aop.syllabi.index') }}">Back to Syllabi</a>
      <a class="btn" href="{{ route('aop.syllabi.downloadDocx', $section) }}">DOCX</a>
      <a class="btn" href="{{ route('aop.syllabi.downloadPdf', $section) }}">PDF</a>
    </div>
  </div>

  <div class="card">
    <p class="muted">
      This is a lightweight HTML preview. The official formatting comes from the DOCX template used for DOCX/PDF output.
    </p>
    <div style="margin-top:12px;">
      <iframe srcdoc="{{ e($html) }}" style="width:100%; height:900px; border:1px solid #ddd; border-radius:10px;"></iframe>
    </div>
  </div>
</x-aop-layout>
EOF

write_file 'routes/web.php' <<'EOF'
<?php

use App\Http\Controllers\Aop\CatalogCourseController;
use App\Http\Controllers\Aop\DashboardController;
use App\Http\Controllers\Aop\InstructorController;
use App\Http\Controllers\Aop\RoomController;
use App\Http\Controllers\Aop\TermController;
use App\Http\Controllers\Aop\SyllabusController;
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

        // Syllabi
        Route::get('/syllabi', [SyllabusController::class, 'index'])->name('syllabi.index');
        Route::post('/syllabi/template', [SyllabusController::class, 'uploadTemplate'])->name('syllabi.template.upload');
        Route::get('/syllabi/sections/{section}', [SyllabusController::class, 'show'])->name('syllabi.show');
        Route::get('/syllabi/sections/{section}/download/html', [SyllabusController::class, 'downloadHtml'])->name('syllabi.downloadHtml');
        Route::get('/syllabi/sections/{section}/download/json', [SyllabusController::class, 'downloadJson'])->name('syllabi.downloadJson');
        Route::get('/syllabi/sections/{section}/download/docx', [SyllabusController::class, 'downloadDocx'])->name('syllabi.downloadDocx');
        Route::get('/syllabi/sections/{section}/download/pdf', [SyllabusController::class, 'downloadPdf'])->name('syllabi.downloadPdf');

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
EOF

write_file 'resources/views/aop/schedule/index.blade.php' <<'EOF'
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
EOF

# Install default syllabus template if missing
TPL_PATH="$ROOT_DIR/storage/app/aop/syllabi/templates/default.docx"
if [ ! -f "$TPL_PATH" ]; then
  mkdir -p "$(dirname "$TPL_PATH")"
  cat > "$TPL_PATH.b64" <<'B64'
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
B64
  base64 -d "$TPL_PATH.b64" > "$TPL_PATH"
  rm -f "$TPL_PATH.b64"
  chown www-data:www-data "$TPL_PATH" 2>/dev/null || true
  chmod 644 "$TPL_PATH" 2>/dev/null || true
  echo "OK: Installed default syllabus template to storage/app/aop/syllabi/templates/default.docx"
else
  echo "OK: Syllabus template already exists; leaving as-is."
fi

echo "OK: Phase 13 applied (template-based syllabi DOCX + DOCX->PDF pipeline)."

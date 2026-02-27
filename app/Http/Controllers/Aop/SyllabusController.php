<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Syllabus;
use App\Models\SyllabusRender;
use App\Models\Term;
use App\Services\DocxPdfConvertService;
use App\Services\DocxTemplateService;
use App\Services\SyllabusDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
                ->with(['offering.catalogCourse', 'instructor'])
                ->whereHas('offering', fn($q) => $q->where('term_id', $term->id))
                ->orderBy('id')
                ->get();
        }

        $templateExists = Storage::disk('local')->exists('aop/syllabi/templates/default.docx');

        // Latest successful docx/pdf per section (best-effort; supports both legacy and newer schema)
        $latestBySection = [];
        if ($term) {
            $latestBySection = $this->latestSuccessfulRendersBySection($term->id);
        }

        return view('aop.syllabi.index', [
            'term' => $term,
            'sections' => $sections,
            'templateExists' => $templateExists,
            'latestBySection' => $latestBySection,
        ]);
    }

    public function uploadTemplate(Request $request)
    {
        $request->validate([
            'template' => ['required', 'file', 'mimes:docx', 'max:10240'],
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

        $history = $this->renderHistoryForSection($section);

        return view('aop.syllabi.show', [
            'section' => $section,
            'packet' => $packet,
            'html' => $html,
            'history' => $history,
        ]);
    }

    public function downloadJson(Section $section, SyllabusDataService $data): StreamedResponse
    {
        $packet = $data->buildPacketForSection($section);
        $name = $this->fileBase($packet) . '.json';

        // Log as SUCCESS (no file saved)
        $syllabus = $this->getOrCreateSyllabus($section);
        $this->recordRender($syllabus->id, $section, 'json', null, 'SUCCESS', null);

        return response()->streamDownload(function () use ($packet) {
            echo json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $name, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function downloadHtml(Section $section, SyllabusDataService $data): StreamedResponse
    {
        $packet = $data->buildPacketForSection($section);
        $html = $this->renderHtmlPreview($packet, $data);
        $name = $this->fileBase($packet) . '.html';

        $syllabus = $this->getOrCreateSyllabus($section);
        $this->recordRender($syllabus->id, $section, 'html', null, 'SUCCESS', null);

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

        $syllabus = $this->getOrCreateSyllabus($section);

        try {
            $repl = $this->buildReplacements($packet, $data);
            $docx->render($templatePath, $repl, $outPath);

            $this->recordRender($syllabus->id, $section, 'docx', $outPath, 'SUCCESS', null);
            $this->pruneSuccessfulRenders($syllabus->id, $section, 'docx');
        } catch (\Throwable $e) {
            $this->recordRender($syllabus->id, $section, 'docx', null, 'ERROR', $e->getMessage());
            throw $e;
        }

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

        $syllabus = $this->getOrCreateSyllabus($section);

        try {
            $repl = $this->buildReplacements($packet, $data);
            $docx->render($templatePath, $repl, $docxPath);

            $actualPdf = $pdf->docxToPdf($docxPath, $outDir, $base);
            if ($actualPdf !== $pdfPath) {
                @copy($actualPdf, $pdfPath);
            }

            $this->recordRender($syllabus->id, $section, 'pdf', $pdfPath, 'SUCCESS', null);
            $this->pruneSuccessfulRenders($syllabus->id, $section, 'pdf');
        } catch (\Throwable $e) {
            $this->recordRender($syllabus->id, $section, 'pdf', null, 'ERROR', $e->getMessage());
            throw $e;
        }

        return response()->streamDownload(function () use ($pdfPath) {
            $fh = fopen($pdfPath, 'rb');
            fpassthru($fh);
            fclose($fh);
        }, basename($pdfPath), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function getOrCreateSyllabus(Section $section): Syllabus
    {
        /** @var Syllabus|null $syllabus */
        $syllabus = Syllabus::query()->where('section_id', $section->id)->first();
        if ($syllabus) {
            return $syllabus;
        }

        return Syllabus::create([
            'section_id' => $section->id,
            'header_snapshot_json' => null,
            'block_order_json' => null,
        ]);
    }

    private function recordRender(
        int $syllabusId,
        Section $section,
        string $format,
        ?string $absolutePath,
        string $status,
        ?string $errorMessage
    ): void {
        $termId = $section->offering?->term_id;
        $now = now();

        $storagePath = null;
        $size = null;
        $sha1 = null;
        $sha256 = null;

        if ($absolutePath && is_file($absolutePath)) {
            $rel = str_replace(storage_path('app') . '/', '', $absolutePath);
            $storagePath = $rel;
            $size = filesize($absolutePath) ?: null;
            $sha1 = @sha1_file($absolutePath) ?: null;
            $sha256 = @hash_file('sha256', $absolutePath) ?: null;
        }

        $row = [];

        // Required legacy columns
        if (Schema::hasColumn('syllabus_renders', 'syllabus_id')) {
            $row['syllabus_id'] = $syllabusId;
        }
        if (Schema::hasColumn('syllabus_renders', 'format')) {
            $row['format'] = $format;
        }

        // Legacy required path (NOT NULL)
        if (Schema::hasColumn('syllabus_renders', 'path')) {
            $row['path'] = $storagePath ?? '';
        }

        // Newer optional columns
        if (Schema::hasColumn('syllabus_renders', 'term_id')) {
            $row['term_id'] = $termId;
        }
        if (Schema::hasColumn('syllabus_renders', 'section_id')) {
            $row['section_id'] = $section->id;
        }
        if (Schema::hasColumn('syllabus_renders', 'storage_path')) {
            $row['storage_path'] = $storagePath;
        }
        if (Schema::hasColumn('syllabus_renders', 'file_size')) {
            $row['file_size'] = $size;
        }
        if (Schema::hasColumn('syllabus_renders', 'sha1')) {
            $row['sha1'] = $sha1;
        }
        if (Schema::hasColumn('syllabus_renders', 'completed_at')) {
            $row['completed_at'] = $now;
        }

        // Legacy optional columns
        if (Schema::hasColumn('syllabus_renders', 'sha256')) {
            $row['sha256'] = $sha256;
        }
        if (Schema::hasColumn('syllabus_renders', 'rendered_at')) {
            $row['rendered_at'] = $now;
        }
        if (Schema::hasColumn('syllabus_renders', 'status')) {
            $row['status'] = $status;
        }
        if (Schema::hasColumn('syllabus_renders', 'error_message')) {
            $row['error_message'] = $errorMessage;
        }

        // Timestamps
        if (Schema::hasColumn('syllabus_renders', 'created_at')) {
            $row['created_at'] = $now;
        }
        if (Schema::hasColumn('syllabus_renders', 'updated_at')) {
            $row['updated_at'] = $now;
        }

        // Insert without Eloquent to avoid fillable/mass-assignment mismatches with legacy schema
        DB::table('syllabus_renders')->insert($row);
    }

    private function pruneSuccessfulRenders(int $syllabusId, Section $section, string $format): void
    {
        // Keep the 2 most recent SUCCESS renders per (syllabus_id, format)
        $q = SyllabusRender::query();

        if (Schema::hasColumn('syllabus_renders', 'syllabus_id')) {
            $q->where('syllabus_id', $syllabusId);
        }
        $q->where('format', $format);

        if (Schema::hasColumn('syllabus_renders', 'status')) {
            $q->where('status', 'SUCCESS');
        }

        // Prefer rendered_at/completed_at if present
        if (Schema::hasColumn('syllabus_renders', 'rendered_at')) {
            $q->orderByDesc('rendered_at');
        } elseif (Schema::hasColumn('syllabus_renders', 'completed_at')) {
            $q->orderByDesc('completed_at');
        } else {
            $q->orderByDesc('id');
        }

        $toKeep = $q->limit(2)->pluck('id')->all();

        $delQ = SyllabusRender::query();
        if (Schema::hasColumn('syllabus_renders', 'syllabus_id')) {
            $delQ->where('syllabus_id', $syllabusId);
        }
        $delQ->where('format', $format);
        if (Schema::hasColumn('syllabus_renders', 'status')) {
            $delQ->where('status', 'SUCCESS');
        }
        if (!empty($toKeep)) {
            $delQ->whereNotIn('id', $toKeep);
        }

        $old = $delQ->get();
        foreach ($old as $r) {
            // Best-effort delete file(s)
            $p = null;
            if (isset($r->storage_path) && $r->storage_path) {
                $p = $r->storage_path;
            } elseif (isset($r->path) && $r->path) {
                $p = $r->path;
            }
            if ($p) {
                Storage::disk('local')->delete($p);
            }
            $r->delete();
        }
    }

    private function renderHistoryForSection(Section $section)
    {
        $syllabus = Syllabus::query()->where('section_id', $section->id)->first();
        if (!$syllabus) {
            return collect();
        }

        $q = SyllabusRender::query()->where('syllabus_id', $syllabus->id)->orderByDesc('id')->limit(50);
        return $q->get();
    }

    private function latestSuccessfulRendersBySection(int $termId): array
    {
        // Return array keyed by "sectionId:format" => render row
        // We’ll use whichever identifier columns exist.

        // If section_id exists and is populated, use it.
        if (Schema::hasColumn('syllabus_renders', 'section_id')) {
            $q = SyllabusRender::query()
                ->whereIn('format', ['docx', 'pdf'])
                ->whereNotNull('section_id');

            if (Schema::hasColumn('syllabus_renders', 'term_id')) {
                $q->where('term_id', $termId);
            }
            if (Schema::hasColumn('syllabus_renders', 'status')) {
                $q->where('status', 'SUCCESS');
            }

            $q->orderByDesc('id');

            $rows = $q->get();
            $out = [];
            foreach ($rows as $r) {
                $key = $r->section_id . ':' . $r->format;
                if (!isset($out[$key])) {
                    $out[$key] = $r;
                }
            }
            return $out;
        }

        // Fallback: join through syllabi.section_id
        $rows = SyllabusRender::query()
            ->select('syllabus_renders.*', 'syllabi.section_id as section_id')
            ->join('syllabi', 'syllabi.id', '=', 'syllabus_renders.syllabus_id')
            ->whereIn('syllabus_renders.format', ['docx', 'pdf'])
            ->orderByDesc('syllabus_renders.id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $key = $r->section_id . ':' . $r->format;
            if (!isset($out[$key])) {
                $out[$key] = $r;
            }
        }
        return $out;
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
            'DELIVERY_MODE' => $this->formatDeliveryMode($packet['section']['modality'] ?? ''),
            'LOCATION' => $meeting['location'] ?? 'TBD',
            'MEETING_DAYS' => $meeting['days'] ?? 'TBD',
            'MEETING_TIME' => $meeting['time'] ?? 'TBD',

            'COREQUISITES' => ($packet['course']['corequisites'] ?? '') !== '' ? $packet['course']['corequisites'] : 'none',
            'OFFICE_HOURS' => $data->formatOfficeHoursLine($packet['office_hours'] ?? []),

            'COURSE_DESCRIPTION' => ($packet['course']['description'] ?? '') !== '' ? $packet['course']['description'] : 'TBD',
            'COURSE_OBJECTIVES' => ($packet['course']['objectives'] ?? '') !== '' ? $packet['course']['objectives'] : 'TBD',
            'REQUIRED_MATERIALS' => ($packet['course']['required_materials'] ?? '') !== '' ? $packet['course']['required_materials'] : 'TBD',
        ];
    }

    private function formatDeliveryMode(string $modality): string
    {
        return match ($modality) {
            'IN_PERSON' => 'In Person',
            'HYBRID' => 'Hybrid',
            'ONLINE' => 'Online',
            default => $modality !== '' ? $modality : 'TBD',
        };
    }

    private function fileBase(array $packet): string
    {
        $term = $packet['term']['code'] ?? 'TERM';
        $code = $packet['course']['code'] ?? 'COURSE';
        $sec = $packet['section']['code'] ?? '00';
        return 'syllabus_' . strtolower($term) . '_' . strtolower(str_replace([' ', '/'], ['-', '-'], $code)) . '_' . $sec;
    }
}

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

    private function formatDeliveryMode(string $modality): string
    {
        return match ($modality) {
            'IN_PERSON' => 'In Person',
            'HYBRID' => 'Hybrid',
            'ONLINE' => 'Online',
            default => 'TBD',
        };
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

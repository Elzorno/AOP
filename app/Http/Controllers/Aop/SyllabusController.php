<?php

namespace App\Http\Controllers\Aop;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Syllabus;
use App\Models\SyllabusBlock;
use App\Models\SyllabusRender;
use App\Models\Term;
use App\Services\DocxPdfConvertService;
use App\Services\DocxTemplateService;
use App\Services\SyllabusDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SyllabusController extends Controller
{
    private function activeTermOrFail(): Term
    {
        $term = Term::where('is_active', true)->first();
        abort_if(!$term, 400, 'No active term is set. Go to Terms and set an active term.');

        return $term;
    }

    private function ensureSectionInActiveTerm(Section $section): Term
    {
        $term = $this->activeTermOrFail();
        $section->loadMissing('offering');
        abort_if(!$section->offering || $section->offering->term_id !== $term->id, 404, 'Section not found in active term.');

        return $term;
    }

    private function syllabusBlocks()
    {
        return SyllabusBlock::query()
            ->orderByRaw("CASE WHEN category IS NULL OR TRIM(category) = '' THEN 1 ELSE 0 END")
            ->orderBy('category')
            ->orderBy('id')
            ->get()
            ->each(function (SyllabusBlock $block): void {
                $markdown = $this->normalizeMarkdown((string) ($block->content_html ?? ''));
                $block->setAttribute('content_markdown', $markdown);
                $block->setAttribute('content_rendered', $this->renderMarkdownHtml($markdown));
                $block->setAttribute('content_preview_text', $this->markdownToPreviewText($markdown, 180));
            });
    }

    private function validateBlock(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:255'],
            'content_html' => ['nullable', 'string'],
            'is_locked' => ['nullable', 'boolean'],
        ]);

        $validated['title'] = trim((string) ($validated['title'] ?? ''));
        $validated['category'] = trim((string) ($validated['category'] ?? '')) ?: null;
        $validated['version'] = trim((string) ($validated['version'] ?? '')) ?: null;
        $validated['content_html'] = $this->normalizeMarkdown((string) ($validated['content_html'] ?? ''));
        $validated['is_locked'] = $request->boolean('is_locked');

        return $validated;
    }

    private function normalizeMarkdown(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return rtrim($content);
    }

    private function renderMarkdownHtml(string $markdown): string
    {
        $markdown = $this->normalizeMarkdown($markdown);
        if ($markdown === '') {
            return '<p>—</p>';
        }

        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    private function markdownToPreviewText(string $markdown, int $limit = 180): string
    {
        $rendered = strip_tags($this->renderMarkdownHtml($markdown));
        $rendered = preg_replace('/\s+/u', ' ', $rendered ?? '');
        $rendered = trim((string) $rendered);

        return $rendered !== '' ? Str::limit($rendered, $limit) : '—';
    }

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
            'blocks' => $this->syllabusBlocks(),
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

    public function createBlock()
    {
        return view('aop.syllabi.blocks.create');
    }

    public function storeBlock(Request $request)
    {
        $block = SyllabusBlock::create($this->validateBlock($request));

        return redirect()->route('aop.syllabi.index')
            ->with('status', 'Syllabus block “' . $block->title . '” created.');
    }

    public function editBlock(SyllabusBlock $block)
    {
        return view('aop.syllabi.blocks.edit', [
            'block' => $block,
        ]);
    }

    public function updateBlock(Request $request, SyllabusBlock $block)
    {
        $block->update($this->validateBlock($request));

        return redirect()->route('aop.syllabi.index')
            ->with('status', 'Syllabus block “' . $block->title . '” updated.');
    }

    public function destroyBlock(SyllabusBlock $block)
    {
        if ($block->is_locked) {
            return redirect()->route('aop.syllabi.index')
                ->with('status', 'Protected syllabus blocks must be unprotected before deletion.');
        }

        $title = $block->title;
        $block->delete();

        return redirect()->route('aop.syllabi.index')
            ->with('status', 'Syllabus block “' . $title . '” deleted.');
    }

    public function show(Section $section, SyllabusDataService $data)
    {
        $this->ensureSectionInActiveTerm($section);

        $packet = $data->buildPacketForSection($section);
        $html = $this->renderHtmlPreview($packet, $data);

        $history = $this->renderHistoryForSection($section);

        return view('aop.syllabi.show', [
            'section' => $section,
            'packet' => $packet,
            'html' => $html,
            'history' => $history,
            'blocks' => collect($packet['blocks'] ?? []),
        ]);
    }

    public function downloadJson(Section $section, SyllabusDataService $data): StreamedResponse
    {
        $this->ensureSectionInActiveTerm($section);

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
        $this->ensureSectionInActiveTerm($section);

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
        $this->ensureSectionInActiveTerm($section);

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
        $this->ensureSectionInActiveTerm($section);

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
            . '<style>body{font-family:Arial,Helvetica,sans-serif;max-width:850px;margin:24px auto;line-height:1.35} h1{margin-bottom:0} .muted{color:#666} .box{border:1px solid #ddd;padding:12px;border-radius:10px;margin:12px 0} table{width:100%;border-collapse:collapse} td{padding:6px 8px;vertical-align:top;border-bottom:1px solid #eee} .markdown-body{line-height:1.55} .markdown-body p:first-child{margin-top:0} .markdown-body p:last-child{margin-bottom:0} .markdown-body ul,.markdown-body ol{padding-left:22px} .markdown-body code{background:#f3f4f6;padding:2px 6px;border-radius:6px} .markdown-body pre{background:#0f172a;color:#e5e7eb;padding:12px;border-radius:10px;overflow:auto}</style>'
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
            . $this->renderCustomBlocksHtml($packet['blocks'] ?? [])
            . '<p class="muted">Template-driven DOCX/PDF output is available via the download buttons.</p>'
            . '</body></html>';
    }

    private function renderCustomBlocksHtml(array $blocks): string
    {
        if (count($blocks) === 0) {
            return '';
        }

        $escape = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $html = '<h2>Shared Syllabus Blocks</h2>';

        foreach ($blocks as $block) {
            $title = trim((string) ($block['title'] ?? ''));
            $category = trim((string) ($block['category'] ?? ''));
            $content = $this->normalizeMarkdown((string) ($block['content'] ?? ''));

            $html .= '<div style="border:1px solid #ddd;padding:12px;border-radius:10px;margin:12px 0;">';

            if ($title !== '') {
                $html .= '<h3 style="margin:0 0 6px 0;">' . $escape($title) . '</h3>';
            }

            if ($category !== '') {
                $html .= '<div class="muted" style="margin-bottom:8px;">' . $escape($category) . '</div>';
            }

            $html .= '<div class="markdown-body">' . $this->renderMarkdownHtml($content) . '</div>';
            $html .= '</div>';
        }

        return $html;
    }

    private function renderCustomBlocksText(array $blocks): string
    {
        if (count($blocks) === 0) {
            return '';
        }

        $chunks = [];
        foreach ($blocks as $block) {
            $title = trim((string) ($block['title'] ?? ''));
            $category = trim((string) ($block['category'] ?? ''));
            $content = trim((string) ($block['content'] ?? ''));

            $parts = [];
            if ($title !== '') {
                $parts[] = $title;
            }
            if ($category !== '') {
                $parts[] = '[' . $category . ']';
            }
            if ($content !== '') {
                $parts[] = $content;
            }

            $line = implode("\n", $parts);
            if ($line !== '') {
                $chunks[] = $line;
            }
        }

        return implode("\n\n", $chunks);
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
            'CUSTOM_BLOCKS' => $this->renderCustomBlocksText($packet['blocks'] ?? []),
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

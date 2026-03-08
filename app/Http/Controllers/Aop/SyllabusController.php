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

    private function normalizeMultilineText(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;

        return trim($content);
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
                ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
                ->orderBy('id')
                ->get();
        }

        $templateExists = Storage::disk('local')->exists('aop/syllabi/templates/default.docx');

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
        $term = $this->ensureSectionInActiveTerm($section);

        $packet = $data->buildPacketForSection($section);
        $html = $this->renderHtmlPreview($packet, $data);
        $history = $this->renderHistoryForSection($section);

        return view('aop.syllabi.show', [
            'section' => $section,
            'term' => $term,
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

        if (Schema::hasColumn('syllabus_renders', 'syllabus_id')) {
            $row['syllabus_id'] = $syllabusId;
        }
        if (Schema::hasColumn('syllabus_renders', 'format')) {
            $row['format'] = $format;
        }

        if (Schema::hasColumn('syllabus_renders', 'path')) {
            $row['path'] = $storagePath ?? '';
        }

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

        if (Schema::hasColumn('syllabus_renders', 'created_at')) {
            $row['created_at'] = $now;
        }
        if (Schema::hasColumn('syllabus_renders', 'updated_at')) {
            $row['updated_at'] = $now;
        }

        DB::table('syllabus_renders')->insert($row);
    }

    private function pruneSuccessfulRenders(int $syllabusId, Section $section, string $format): void
    {
        $q = SyllabusRender::query();

        if (Schema::hasColumn('syllabus_renders', 'syllabus_id')) {
            $q->where('syllabus_id', $syllabusId);
        }
        $q->where('format', $format);

        if (Schema::hasColumn('syllabus_renders', 'status')) {
            $q->where('status', 'SUCCESS');
        }

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

        return SyllabusRender::query()
            ->where('syllabus_id', $syllabus->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    private function latestSuccessfulRendersBySection(int $termId): array
    {
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
        return view('aop.syllabi.preview', $this->buildPreviewViewData($packet, $data))->render();
    }

    private function buildPreviewViewData(array $packet, SyllabusDataService $data): array
    {
        $replacements = $this->buildReplacements($packet, $data);
        $meetingRows = $this->meetingRows($packet['meeting_blocks'] ?? []);
        $officeHourRows = $this->officeHourRows($packet['office_hours'] ?? []);

        $locationLines = $this->uniqueNonEmpty(array_map(fn (array $row) => $row['room'], $meetingRows));
        $daysTimesLines = array_map(fn (array $row) => $row['days_times_line'], $meetingRows);
        $termLine = trim(($packet['term']['code'] ?? '') . (($packet['term']['name'] ?? '') !== '' ? ' — ' . $packet['term']['name'] : ''));

        return [
            'packet' => $packet,
            'replacements' => $replacements,
            'meetingRows' => $meetingRows,
            'officeHourRows' => $officeHourRows,
            'locationLines' => $locationLines !== [] ? $locationLines : ['TBD'],
            'daysTimesLines' => $daysTimesLines !== [] ? $daysTimesLines : ['TBD'],
            'termLine' => $termLine !== '' ? $termLine : 'TBD',
            'generatedDate' => $replacements['SYLLABUS_DATE'] ?: now()->toDateString(),
            'departmentLine' => $replacements['DEPARTMENT_LINE'] ?: 'Academic Ops Platform Syllabus Preview',
        ];
    }

    private function meetingRows(array $meetingBlocks): array
    {
        $rows = [];

        foreach ($meetingBlocks as $block) {
            $type = $this->formatMeetingType((string) ($block['type'] ?? ''));
            $days = $this->daysToString((array) ($block['days'] ?? []));
            $start = trim((string) ($block['start'] ?? ''));
            $end = trim((string) ($block['end'] ?? ''));
            $time = trim($start . (($start !== '' || $end !== '') ? '–' : '') . $end, '– ');
            $room = trim((string) ($block['room'] ?? ''));
            $notes = trim((string) ($block['notes'] ?? ''));

            $summaryParts = [];
            if ($type !== '') {
                $summaryParts[] = $type;
            }
            $daysTime = trim($days . ($days !== '' && $time !== '' ? ' ' : '') . $time);
            if ($daysTime !== '') {
                $summaryParts[] = $daysTime;
            }
            if ($room !== '') {
                $summaryParts[] = $room;
            }

            $summary = implode(' — ', $summaryParts);
            if ($summary === '') {
                $summary = 'TBD';
            }
            if ($notes !== '') {
                $summary .= ' (' . $notes . ')';
            }

            $rows[] = [
                'type' => $type !== '' ? $type : 'Meeting',
                'days' => $days,
                'time' => $time,
                'room' => $room,
                'notes' => $notes,
                'summary' => $summary,
                'days_times_line' => trim($days . ($days !== '' && $time !== '' ? ' ' : '') . $time) ?: 'TBD',
            ];
        }

        return $rows;
    }

    private function officeHourRows(array $officeHours): array
    {
        $rows = [];

        foreach ($officeHours as $block) {
            $days = $this->daysToString((array) ($block['days'] ?? []));
            $start = trim((string) ($block['start'] ?? ''));
            $end = trim((string) ($block['end'] ?? ''));
            $time = trim($start . (($start !== '' || $end !== '') ? '–' : '') . $end, '– ');
            $notes = trim((string) ($block['notes'] ?? ''));

            $summary = trim($days . ($days !== '' && $time !== '' ? ' ' : '') . $time);
            if ($summary === '') {
                $summary = 'TBD';
            }
            if ($notes !== '') {
                $summary .= ' (' . $notes . ')';
            }

            $rows[] = [
                'days' => $days,
                'time' => $time,
                'notes' => $notes,
                'summary' => $summary,
            ];
        }

        return $rows;
    }

    private function buildReplacements(array $packet, SyllabusDataService $data): array
    {
        $meeting = $data->formatMeetingInfo($packet['meeting_blocks'] ?? []);
        $meetingRows = $this->meetingRows($packet['meeting_blocks'] ?? []);
        $officeHourRows = $this->officeHourRows($packet['office_hours'] ?? []);

        $credits = $packet['course']['credits_text'] ?? '';
        if ($credits === '' && ($packet['course']['credits_min'] ?? null) !== null) {
            $min = $packet['course']['credits_min'];
            $max = $packet['course']['credits_max'];
            $credits = ($max !== null && $max != $min) ? ($min . '-' . $max) : (string) $min;
        }

        $uniqueRooms = $this->uniqueNonEmpty(array_map(fn (array $row) => $row['room'], $meetingRows));
        $meetingDays = $this->uniqueNonEmpty(array_map(fn (array $row) => $row['days'], $meetingRows));
        $meetingTimes = $this->uniqueNonEmpty(array_map(fn (array $row) => $row['time'], $meetingRows));
        $meetingLines = $this->uniqueNonEmpty(array_map(fn (array $row) => $row['summary'], $meetingRows));
        $officeHourLines = $this->uniqueNonEmpty(array_map(fn (array $row) => $row['summary'], $officeHourRows));

        return [
            'COURSE_CODE' => $packet['course']['code'] ?? '',
            'COURSE_TITLE' => $packet['course']['title'] ?? '',
            'TERM_CODE' => $packet['term']['code'] ?? '',
            'TERM_NAME' => $packet['term']['name'] ?? '',
            'SECTION_CODE' => $packet['section']['code'] ?? '',
            'DEPARTMENT_LINE' => ($packet['course']['department'] ?? '') !== ''
                ? $packet['course']['department']
                : 'Department of Engineering Technologies – Information Security/Cyber Security',

            'INSTRUCTOR_NAME' => $packet['instructor']['name'] ?? '',
            'INSTRUCTOR_EMAIL' => $packet['instructor']['email'] ?? '',

            'SYLLABUS_DATE' => now()->toDateString(),

            'CREDIT_HOURS' => $credits !== '' ? $credits : 'TBD',
            'DELIVERY_MODE' => $this->formatDeliveryMode($packet['section']['modality'] ?? ''),
            'LOCATION' => $uniqueRooms !== [] ? implode('; ', $uniqueRooms) : (($meeting['location'] ?? '') !== '' ? $meeting['location'] : 'TBD'),
            'MEETING_DAYS' => $meetingDays !== [] ? implode('; ', $meetingDays) : (($meeting['days'] ?? '') !== '' ? $meeting['days'] : 'TBD'),
            'MEETING_TIME' => $meetingTimes !== [] ? implode('; ', $meetingTimes) : (($meeting['time'] ?? '') !== '' ? $meeting['time'] : 'TBD'),
            'MEETING_LINES' => $meetingLines !== [] ? implode("\n", $meetingLines) : 'TBD',

            'PREREQUISITES' => ($packet['course']['prerequisites'] ?? '') !== '' ? $packet['course']['prerequisites'] : 'none',
            'COREQUISITES' => ($packet['course']['corequisites'] ?? '') !== '' ? $packet['course']['corequisites'] : 'none',
            'OFFICE_HOURS' => $data->formatOfficeHoursLine($packet['office_hours'] ?? []),
            'OFFICE_HOURS_LINES' => $officeHourLines !== [] ? implode("\n", $officeHourLines) : 'TBD',

            'COURSE_DESCRIPTION' => ($packet['course']['description'] ?? '') !== ''
                ? $this->normalizeMultilineText((string) $packet['course']['description'])
                : 'TBD',
            'COURSE_OBJECTIVES' => ($packet['course']['objectives'] ?? '') !== ''
                ? $this->normalizeMultilineText((string) $packet['course']['objectives'])
                : 'TBD',
            'REQUIRED_MATERIALS' => ($packet['course']['required_materials'] ?? '') !== ''
                ? $this->normalizeMultilineText((string) $packet['course']['required_materials'])
                : 'TBD',
            'COURSE_NOTES' => ($packet['course']['notes'] ?? '') !== ''
                ? $this->normalizeMultilineText((string) $packet['course']['notes'])
                : '',
            'SECTION_NOTES' => ($packet['section']['notes'] ?? '') !== ''
                ? $this->normalizeMultilineText((string) $packet['section']['notes'])
                : '',
            'CUSTOM_BLOCKS' => $this->renderCustomBlocksText($packet['blocks'] ?? []),
        ];
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
            $content = $this->markdownToStructuredText((string) ($block['content'] ?? ''));

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

    private function markdownToStructuredText(string $markdown): string
    {
        $markdown = $this->normalizeMarkdown($markdown);
        if ($markdown === '') {
            return '';
        }

        $markdown = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1 ($2)', $markdown) ?? $markdown;
        $markdown = preg_replace('/^```[^\n]*\n?/m', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/\n```$/m', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/`([^`]+)`/', '$1', $markdown) ?? $markdown;
        $markdown = preg_replace('/(\*\*|__)(.*?)\1/', '$2', $markdown) ?? $markdown;
        $markdown = preg_replace('/(\*|_)(.*?)\1/', '$2', $markdown) ?? $markdown;
        $markdown = strip_tags($markdown);

        $out = [];
        foreach (preg_split('/\n/', $markdown) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $out[] = '';
                continue;
            }

            if (preg_match('/^(#{1,6})\s*(.+)$/', $trimmed, $m)) {
                $out[] = trim($m[2]);
                $out[] = '';
                continue;
            }

            if (preg_match('/^[-*+]\s+(.+)$/', $trimmed, $m)) {
                $out[] = '• ' . trim($m[1]);
                continue;
            }

            if (preg_match('/^(\d+)[\.)]\s+(.+)$/', $trimmed, $m)) {
                $out[] = $m[1] . '. ' . trim($m[2]);
                continue;
            }

            if (preg_match('/^>\s?(.+)$/', $trimmed, $m)) {
                $out[] = 'Note: ' . trim($m[1]);
                continue;
            }

            if (str_starts_with($trimmed, '|') && str_ends_with($trimmed, '|')) {
                $cells = array_values(array_filter(array_map(
                    fn (string $cell) => trim($cell),
                    explode('|', trim($trimmed, '| '))
                ), fn (string $cell) => $cell !== ''));

                if ($cells !== []) {
                    $out[] = implode(' | ', $cells);
                    continue;
                }
            }

            $out[] = $trimmed;
        }

        $text = implode("\n", $out);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
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

    private function formatMeetingType(string $type): string
    {
        $type = trim($type);
        if ($type === '') {
            return '';
        }

        $type = str_replace('_', ' ', strtolower($type));

        return Str::title($type);
    }

    private function daysToString(array $days): string
    {
        $order = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7];
        $days = array_values(array_filter($days, fn ($day) => is_string($day) && trim($day) !== ''));
        usort($days, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return implode(', ', $days);
    }

    private function uniqueNonEmpty(array $values): array
    {
        $clean = [];

        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (!in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }

        return $clean;
    }

    private function fileBase(array $packet): string
    {
        $term = $packet['term']['code'] ?? 'TERM';
        $code = $packet['course']['code'] ?? 'COURSE';
        $sec = $packet['section']['code'] ?? '00';

        return 'syllabus_' . strtolower($term) . '_' . strtolower(str_replace([' ', '/'], ['-', '-'], $code)) . '_' . $sec;
    }
}

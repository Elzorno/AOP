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

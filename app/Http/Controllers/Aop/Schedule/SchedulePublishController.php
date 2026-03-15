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
use App\Services\ScheduleConflictService;
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

        $readiness = $term ? $this->buildPublishReadinessSummary($term) : null;

        return view('aop.schedule.publish.index', [
            'term' => $term,
            'publications' => $publications,
            'latest' => $latest,
            'readiness' => $readiness,
        ]);
    }

    public function store(Request $request)
    {
        $term = $this->activeTermOrFail();

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
            'confirm_publish_with_issues' => ['nullable', 'boolean'],
        ]);

        abort_unless($term->schedule_locked, 403, 'Schedule must be locked before publishing a snapshot.');

        $readiness = $this->buildPublishReadinessSummary($term);
        if (($readiness['total_blockers'] ?? 0) > 0 && !$request->boolean('confirm_publish_with_issues')) {
            return back()
                ->withErrors([
                    'publish_gate' => 'Readiness checks still show blockers. Review issues below or confirm publish anyway.',
                ])
                ->withInput();
        }

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
            // Skip empty files (no classes + no office)
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

        // Create zip bundles (skip missing files safely)
        $this->createZipFromDir($base . '/instructors', $base . '/instructors.zip');
        $this->createZipFromDir($base . '/rooms', $base . '/rooms.zip');

        // Token for public view (Phase 9)
        $token = bin2hex(random_bytes(32));

        // Persist publication record
        SchedulePublication::create([
            'term_id' => $term->id,
            'version' => $nextVersion,
            'notes' => $data['notes'] ?? null,
            'published_at' => now(),
            'published_by_user_id' => auth()->id(),
            'storage_base_path' => $base,
            'public_token' => $token,
        ]);

        return redirect()
            ->route('aop.schedule.publish.index')
            ->with('status', "Published schedule snapshot v{$nextVersion} for {$term->code}.");
    }

    private function buildPublishReadinessSummary(Term $term): array
    {
        $sections = Section::query()
            ->with(['meetingBlocks', 'offering.catalogCourse', 'instructor'])
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $meetingBlocks = MeetingBlock::query()
            ->with(['section.offering.catalogCourse', 'section.instructor', 'room'])
            ->whereHas('section.offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $officeBlocks = OfficeHourBlock::query()
            ->where('term_id', $term->id)
            ->get();

        $sectionsMissingInstructor = $sections->whereNull('instructor_id')->count();
        $sectionsMissingMeetingBlocks = $sections->filter(fn ($s) => $s->meetingBlocks->count() === 0)->count();

        $meetingBlocksMissingRoom = $meetingBlocks
            ->filter(function ($mb) {
                $modality = $mb->section?->modality;
                $requiresRoom = in_array($modality, ['IN_PERSON', 'HYBRID'], true);
                return $requiresRoom && !$mb->room_id;
            })
            ->count();

        $roomConflicts = $this->countRoomConflicts($term, $meetingBlocks);
        $instructorConflicts = $this->countInstructorConflicts($term, $meetingBlocks, $officeBlocks);
        $officeHoursFailing = $this->countOfficeHoursFailing($officeBlocks);
        $instructionalMinutesFailing = $this->countInstructionalMinutesFailing($term, $sections);

        $metrics = [
            'sections_missing_instructor' => $sectionsMissingInstructor,
            'sections_missing_meeting_blocks' => $sectionsMissingMeetingBlocks,
            'meeting_blocks_missing_room' => $meetingBlocksMissingRoom,
            'room_conflicts' => $roomConflicts,
            'instructor_conflicts' => $instructorConflicts,
            'office_hours_failing' => $officeHoursFailing,
            'instructional_minutes_failing' => $instructionalMinutesFailing,
        ];

        return [
            'metrics' => $metrics,
            'total_blockers' => array_sum($metrics),
            'is_ready' => array_sum($metrics) === 0,
        ];
    }

    private function countRoomConflicts(Term $term, $meetingBlocks): int
    {
        $count = 0;
        $grouped = $meetingBlocks->whereNotNull('room_id')->groupBy('room_id');

        foreach ($grouped as $blocks) {
            $list = $blocks->values();
            $n = $list->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countInstructorConflicts(Term $term, $meetingBlocks, $officeBlocks): int
    {
        $count = 0;

        $meetingByInstructor = $meetingBlocks
            ->filter(fn ($mb) => (bool) $mb->section?->instructor_id)
            ->groupBy(fn ($mb) => (int) $mb->section->instructor_id);

        $officeByInstructor = $officeBlocks->groupBy('instructor_id');

        $allInstructorIds = collect(array_unique(array_merge(
            $meetingByInstructor->keys()->all(),
            $officeByInstructor->keys()->all(),
        )));

        foreach ($allInstructorIds as $instructorId) {
            $classList = ($meetingByInstructor[$instructorId] ?? collect())->values();
            $officeList = ($officeByInstructor[$instructorId] ?? collect())->values();

            $n = $classList->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $classList[$i];
                    $b = $classList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }

            $m = $officeList->count();
            for ($i = 0; $i < $m; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $a = $officeList[$i];
                    $b = $officeList[$j];
                    if (!ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }

            foreach ($classList as $classBlock) {
                foreach ($officeList as $officeBlock) {
                    if (!ScheduleConflictService::dayOverlap($classBlock->days_json ?? [], $officeBlock->days_json ?? [])) {
                        continue;
                    }
                    if (!ScheduleConflictService::timesOverlap($classBlock->starts_at, $classBlock->ends_at, $officeBlock->starts_at, $officeBlock->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countOfficeHoursFailing($officeBlocks): int
    {
        $fullTimeInstructors = Instructor::query()
            ->where('is_active', true)
            ->where('is_full_time', true)
            ->get();

        $officeByInstructor = $officeBlocks->groupBy('instructor_id');
        $failing = 0;

        foreach ($fullTimeInstructors as $instructor) {
            $blocks = ($officeByInstructor[$instructor->id] ?? collect())->values();
            $minutesPerWeek = 0;
            $days = [];

            foreach ($blocks as $block) {
                $blockDays = ScheduleConflictService::normalizeDays($block->days_json ?? []);
                $days = array_merge($days, $blockDays);
                $minutesPerWeek += $this->durationMinutes((string) $block->starts_at, (string) $block->ends_at) * count($blockDays);
            }

            $meetsHours = $minutesPerWeek >= 240;
            $meetsDays = count(array_unique($days)) >= 3;

            if (!($meetsHours && $meetsDays)) {
                $failing++;
            }
        }

        return $failing;
    }

    private function countInstructionalMinutesFailing(Term $term, $sections): int
    {
        $weeks = max(1, (int) ($term->weeks_in_term ?? 15));
        $failing = 0;

        foreach ($sections as $section) {
            $course = $section->offering?->catalogCourse;

            $lectureContact = (float) ($course?->lecture_hours_per_week ?? 0);
            $labContact = (float) ($course?->lab_hours_per_week ?? 0);
            $fallbackContact = (float) ($course?->contact_hours_per_week ?? 0);

            if (($lectureContact + $labContact) <= 0 && $fallbackContact > 0) {
                $lectureContact = $fallbackContact;
                $labContact = 0;
            }

            $requiredMinutes = (int) round((($lectureContact * 750) + (($labContact / 3.0) * 2250)) * ($weeks / 15));

            $scheduledPerWeek = 0;
            foreach ($section->meetingBlocks as $mb) {
                $duration = $this->durationMinutes((string) $mb->starts_at, (string) $mb->ends_at);
                $scheduledPerWeek += $duration * count(ScheduleConflictService::normalizeDays($mb->days_json ?? []));
            }

            $scheduledMinutes = (int) round($scheduledPerWeek * $weeks);
            if ($requiredMinutes > 0 && $scheduledMinutes < $requiredMinutes) {
                $failing++;
            }
        }

        return $failing;
    }

    private function durationMinutes(string $startsAt, string $endsAt): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', substr($startsAt, 0, 5)));
        [$endHour, $endMinute] = array_map('intval', explode(':', substr($endsAt, 0, 5)));
        $start = ($startHour * 60) + $startMinute;
        $end = ($endHour * 60) + $endMinute;

        return max(0, $end - $start);
    }

    public function downloadTerm(SchedulePublication $publication): StreamedResponse
    {
        $this->assertAdminAndTermAccess($publication);
        return $this->downloadLocalFile($publication->storage_base_path . '/term_schedule.csv', $this->termFileName($publication, 'term_schedule.csv'));
    }

    public function downloadInstructorsZip(SchedulePublication $publication): StreamedResponse
    {
        $this->assertAdminAndTermAccess($publication);
        return $this->downloadLocalFile($publication->storage_base_path . '/instructors.zip', $this->termFileName($publication, 'instructors.zip'));
    }

    public function downloadRoomsZip(SchedulePublication $publication): StreamedResponse
    {
        $this->assertAdminAndTermAccess($publication);
        return $this->downloadLocalFile($publication->storage_base_path . '/rooms.zip', $this->termFileName($publication, 'rooms.zip'));
    }

    private function assertAdminAndTermAccess(SchedulePublication $publication): void
    {
        // Routes already require admin, but keep this explicit.
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

        $fullZipPath = storage_path('app/' . ltrim($zipPath, '/'));

        // Ensure parent directory exists
        $zipParent = dirname($fullZipPath);
        if (!is_dir($zipParent)) {
            @mkdir($zipParent, 0755, true);
        }

        // Remove old zip if present
        if (file_exists($fullZipPath)) {
            @unlink($fullZipPath);
        }

        $zip = new ZipArchive();
        $ok = $zip->open($fullZipPath, ZipArchive::CREATE);
        if ($ok !== true) {
            return;
        }

        // Add files that actually exist on disk
        $files = $disk->allFiles($dirPath);
        foreach ($files as $file) {
            $absPath = storage_path('app/' . ltrim($file, '/'));
            if (!is_file($absPath)) {
                continue;
            }
            $localName = str_replace($dirPath . '/', '', $file);
            $zip->addFile($absPath, $localName);
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
        $lines = preg_split("/\r\n|\r|\n/", trim($csv));
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
            if ((int)$mb->section->instructor_id !== (int)$instructor->id) {
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
            if ((int)$ob->instructor_id !== (int)$instructor->id) {
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
            if ((int)$mb->room_id !== (int)$room->id) {
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

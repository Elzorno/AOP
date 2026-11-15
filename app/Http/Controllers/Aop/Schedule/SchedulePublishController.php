<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\SchedulePublication;
use App\Models\Section;
use App\Models\Term;
use App\Services\PublicationArtifactDownloadService;
use App\Services\ScheduleConflictService;
use App\Support\SafeCsv;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class SchedulePublishController extends Controller
{
    private const DAYS_ORDER = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public function index(Term $term)
    {
        return redirect()
            ->route('aop.schedule.reports.index', $term)
            ->with('status', 'Schedule snapshots have been replaced by the registrar CSV export.');
    }

    public function store(Term $term, ScheduleReportsController $reports): StreamedResponse
    {
        return $reports->exportRegistrar($term);
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

        $openLabs = OpenLab::query()
            ->with(['room', 'labTech'])
            ->where('term_id', $term->id)
            ->get();

        $sectionsMissingInstructor = $sections->filter(fn (Section $section) => ! $section->hasInstructorAssignment())->count();
        $sectionsMissingMeetingBlocks = $sections
            ->filter(fn (Section $section) => $section->requiresMeetingBlocks() && $section->meetingBlocks->isEmpty())
            ->count();

        $meetingBlocksMissingRoom = $meetingBlocks
            ->filter(function ($mb) {
                $modality = $mb->section?->modality;
                $requiresRoom = in_array($modality, ['IN_PERSON', 'HYBRID'], true);

                return $requiresRoom && ! $mb->room_id;
            })
            ->count();

        $roomConflicts = $this->countRoomConflicts($term, $meetingBlocks, $openLabs);
        $instructorConflicts = $this->countInstructorConflicts($term, $meetingBlocks, $officeBlocks);
        $labTechConflicts = $this->countLabTechConflicts($term, $openLabs);
        $labTechOverLimit = app(\App\Services\LabTechWorkloadService::class)
            ->summariesForTerm($term)
            ->filter(fn (array $row) => $row['is_over_limit'])
            ->count();
        $officeHoursFailing = $this->countOfficeHoursFailing($officeBlocks);
        $instructionalMinutesFailing = $this->countInstructionalMinutesFailing($term, $sections);

        $metrics = [
            'sections_missing_instructor' => $sectionsMissingInstructor,
            'sections_missing_meeting_blocks' => $sectionsMissingMeetingBlocks,
            'meeting_blocks_missing_room' => $meetingBlocksMissingRoom,
            'room_conflicts' => $roomConflicts,
            'instructor_conflicts' => $instructorConflicts,
            'labtech_conflicts' => $labTechConflicts,
            'labtech_over_limit' => $labTechOverLimit,
            'office_hours_failing' => $officeHoursFailing,
            'instructional_minutes_failing' => $instructionalMinutesFailing,
        ];

        return [
            'metrics' => $metrics,
            'total_blockers' => array_sum($metrics),
            'is_ready' => array_sum($metrics) === 0,
        ];
    }

    private function countRoomConflicts(Term $term, $meetingBlocks, $openLabs): int
    {
        $count = 0;
        $meetingByRoom = $meetingBlocks->whereNotNull('room_id')->groupBy('room_id');
        $openLabsByRoom = $openLabs->whereNotNull('room_id')->groupBy('room_id');
        $roomIds = collect(array_unique(array_merge(
            $meetingByRoom->keys()->all(),
            $openLabsByRoom->keys()->all(),
        )));

        foreach ($roomIds as $roomId) {
            $classList = ($meetingByRoom[$roomId] ?? collect())->values();
            $openLabList = ($openLabsByRoom[$roomId] ?? collect())->values();
            $n = $classList->count();
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $classList[$i];
                    $b = $classList[$j];
                    if (! ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (! ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }

            $m = $openLabList->count();
            for ($i = 0; $i < $m; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $a = $openLabList[$i];
                    $b = $openLabList[$j];
                    if (! ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (! ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }

            foreach ($classList as $classBlock) {
                foreach ($openLabList as $openLab) {
                    if (! ScheduleConflictService::dayOverlap($classBlock->days_json ?? [], $openLab->days_json ?? [])) {
                        continue;
                    }
                    if (! ScheduleConflictService::timesOverlap($classBlock->starts_at, $classBlock->ends_at, $openLab->starts_at, $openLab->ends_at, (int) ($term->buffer_minutes ?? 0))) {
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
                    if (! ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (! ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
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
                    if (! ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (! ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }

            foreach ($classList as $classBlock) {
                foreach ($officeList as $officeBlock) {
                    if (! ScheduleConflictService::dayOverlap($classBlock->days_json ?? [], $officeBlock->days_json ?? [])) {
                        continue;
                    }
                    if (! ScheduleConflictService::timesOverlap($classBlock->starts_at, $classBlock->ends_at, $officeBlock->starts_at, $officeBlock->ends_at, (int) ($term->buffer_minutes ?? 0))) {
                        continue;
                    }
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countLabTechConflicts(Term $term, $openLabs): int
    {
        $count = 0;
        $byLabTech = $openLabs->groupBy('lab_tech_id');

        foreach ($byLabTech as $openLabList) {
            $list = $openLabList->values();
            $n = $list->count();

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i];
                    $b = $list[$j];
                    if (! ScheduleConflictService::dayOverlap($a->days_json ?? [], $b->days_json ?? [])) {
                        continue;
                    }
                    if (! ScheduleConflictService::timesOverlap($a->starts_at, $a->ends_at, $b->starts_at, $b->ends_at, (int) ($term->buffer_minutes ?? 0))) {
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

            if (! ($meetsHours && $meetsDays)) {
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
            if (! $section->requiresMeetingBlocks()) {
                continue;
            }

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

    public function downloadTerm(SchedulePublication $publication, PublicationArtifactDownloadService $downloads): StreamedResponse
    {
        $this->assertAdminAndTermAccess($publication);

        return $downloads->download($publication->storage_base_path.'/term_schedule.csv', $this->termFileName($publication, 'term_schedule.csv'));
    }

    public function downloadInstructorsZip(SchedulePublication $publication, PublicationArtifactDownloadService $downloads): StreamedResponse
    {
        $this->assertAdminAndTermAccess($publication);

        return $downloads->download($publication->storage_base_path.'/instructors.zip', $this->termFileName($publication, 'instructors.zip'));
    }

    public function downloadRoomsZip(SchedulePublication $publication, PublicationArtifactDownloadService $downloads): StreamedResponse
    {
        $this->assertAdminAndTermAccess($publication);

        return $downloads->download($publication->storage_base_path.'/rooms.zip', $this->termFileName($publication, 'rooms.zip'));
    }

    private function assertAdminAndTermAccess(SchedulePublication $publication): void
    {
        // Routes already require admin, but keep this explicit.
        abort_unless(auth()->check() && auth()->user()->is_admin, 403);
        abort_unless($publication->term_id !== null, 404);
    }

    private function termFileName(SchedulePublication $publication, string $baseName): string
    {
        $term = $publication->term;
        $code = $term?->code ?? 'term';

        return sprintf('aop_%s_v%d_%s', $code, $publication->version, $baseName);
    }

    private function ensureDir(string $storagePath): void
    {
        if (! Storage::disk('local')->exists($storagePath)) {
            Storage::disk('local')->makeDirectory($storagePath);
        }
    }

    private function createZipFromDir(string $dirPath, string $zipPath): void
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($dirPath)) {
            return;
        }

        $fullZipPath = storage_path('app/'.ltrim($zipPath, '/'));

        // Ensure parent directory exists
        $zipParent = dirname($fullZipPath);
        if (! is_dir($zipParent)) {
            @mkdir($zipParent, 0755, true);
        }

        // Remove old zip if present
        if (file_exists($fullZipPath)) {
            @unlink($fullZipPath);
        }

        $zip = new ZipArchive;
        $ok = $zip->open($fullZipPath, ZipArchive::CREATE);
        if ($ok !== true) {
            return;
        }

        // Add files that actually exist on disk
        $files = $disk->allFiles($dirPath);
        foreach ($files as $file) {
            $absPath = storage_path('app/'.ltrim($file, '/'));
            if (! is_file($absPath)) {
                continue;
            }
            $localName = str_replace($dirPath.'/', '', $file);
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
        usort($days, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return implode('/', $days);
    }

    private function time5($time): string
    {
        return substr((string) $time, 0, 5);
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
            SafeCsv::write($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv === false ? '' : $csv;
    }

    private function isCsvOnlyHeader(string $csv): bool
    {
        $lines = preg_split("/\r\n|\r|\n/", trim($csv));

        return is_array($lines) && count(array_filter($lines, fn ($l) => trim($l) !== '')) <= 1;
    }

    private function buildTermCsv(Term $term, $meetingBlocks, $openLabs): string
    {
        $rows = [];
        $rows[] = ['Term', 'Event Kind', 'Display Name', 'Course Code', 'Section Code', 'Assigned Staff', 'Room', 'Meeting Type', 'Days', 'Start', 'End', 'Notes'];

        foreach ($meetingBlocks as $mb) {
            $course = $mb->section->offering->catalogCourse;
            $rows[] = [
                $term->code,
                'CLASS',
                $course->title ?? '',
                $course->code,
                $mb->section->section_code,
                $mb->section->instructor_display_name ?? '',
                $mb->room?->name ?? '',
                $this->meetingTypeLabel($mb->type),
                $this->daysToString($mb->days_json ?? []),
                $this->time5($mb->starts_at),
                $this->time5($mb->ends_at),
                $mb->notes ?? '',
            ];
        }

        foreach ($openLabs as $openLab) {
            $rows[] = [
                $term->code,
                'OPEN_LAB',
                $openLab->name,
                '',
                '',
                $openLab->labTech?->name ?? '',
                $openLab->room?->name ?? '',
                'OPEN_LAB',
                $this->daysToString($openLab->days_json ?? []),
                $this->time5($openLab->starts_at),
                $this->time5($openLab->ends_at),
                $openLab->notes ?? '',
            ];
        }

        return $this->csvWithBom($rows);
    }

    private function buildInstructorCsv(Term $term, Instructor $instructor, $meetingBlocks, $officeBlocks): string
    {
        $rows = [];
        $rows[] = ['Term', 'Instructor', 'Event Kind', 'Course Code', 'Section Code', 'Meeting Type', 'Days', 'Start', 'End', 'Room', 'Notes'];

        foreach ($meetingBlocks as $mb) {
            if ((int) $mb->section->instructor_id !== (int) $instructor->id) {
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
            if ((int) $ob->instructor_id !== (int) $instructor->id) {
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

    private function buildRoomCsv(Term $term, Room $room, $meetingBlocks, $openLabs): string
    {
        $rows = [];
        $rows[] = ['Term', 'Room', 'Event Kind', 'Display Name', 'Course Code', 'Section Code', 'Assigned Staff', 'Meeting Type', 'Days', 'Start', 'End', 'Notes'];

        foreach ($meetingBlocks as $mb) {
            if ((int) $mb->room_id !== (int) $room->id) {
                continue;
            }
            $course = $mb->section->offering->catalogCourse;
            $rows[] = [
                $term->code,
                $room->name,
                'CLASS',
                $course->title ?? '',
                $course->code,
                $mb->section->section_code,
                $mb->section->instructor_display_name ?? '',
                $this->meetingTypeLabel($mb->type),
                $this->daysToString($mb->days_json ?? []),
                $this->time5($mb->starts_at),
                $this->time5($mb->ends_at),
                $mb->notes ?? '',
            ];
        }

        foreach ($openLabs as $openLab) {
            if ((int) $openLab->room_id !== (int) $room->id) {
                continue;
            }
            $rows[] = [
                $term->code,
                $room->name,
                'OPEN_LAB',
                $openLab->name,
                '',
                '',
                $openLab->labTech?->name ?? '',
                'OPEN_LAB',
                $this->daysToString($openLab->days_json ?? []),
                $this->time5($openLab->starts_at),
                $this->time5($openLab->ends_at),
                $openLab->notes ?? '',
            ];
        }

        return $this->csvWithBom($rows);
    }
}

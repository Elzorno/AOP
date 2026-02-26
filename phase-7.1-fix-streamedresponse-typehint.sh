#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mkdir -p "$ROOT_DIR/app/Http/Controllers/Aop/Schedule"

cat > "$ROOT_DIR/app/Http/Controllers/Aop/Schedule/ScheduleReportsController.php" <<'PHP'
<?php

namespace App\Http\Controllers\Aop\Schedule;

use App\Enums\MeetingBlockType;
use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\OfficeHourBlock;
use App\Models\Room;
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

        if (!$term) {
            return view('aop.schedule.reports.index', [
                'term' => null,
                'instructors' => $instructors,
                'rooms' => $rooms,
                'stats' => null,
                'unassigned' => null,
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
            // UTF-8 BOM for Excel
            fwrite($out, "ï»¿");
            $writer($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
PHP

# keep readable by www-data
chown www-data:www-data "$ROOT_DIR/app/Http/Controllers/Aop/Schedule/ScheduleReportsController.php"
chmod 644 "$ROOT_DIR/app/Http/Controllers/Aop/Schedule/ScheduleReportsController.php"

echo "OK: Applied Phase 7.1 hotfix (StreamedResponse return type)."

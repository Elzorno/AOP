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

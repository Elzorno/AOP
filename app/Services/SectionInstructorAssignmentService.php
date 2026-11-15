<?php

namespace App\Services;

use App\Models\Instructor;
use App\Models\ScheduleChangeLog;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SectionInstructorAssignmentService
{
    public function __construct(private readonly ScheduleConflictService $conflicts) {}

    public function updateSection(Term $term, Section $section, array $attributes, ?int $actorId = null): Section
    {
        $this->assertSectionInTerm($term, $section);

        return DB::transaction(function () use ($term, $section, $attributes, $actorId) {
            $before = $this->snapshot($section);
            $assignment = $this->assignmentFrom($attributes);
            $this->validateReassignment($term, collect([$section]), $assignment);
            $section->update([...$attributes, ...$assignment]);
            $this->log($term, $section, $before, $this->snapshot($section), $actorId);

            return $section->fresh(['offering.catalogCourse', 'instructor', 'meetingBlocks.room']);
        }, 3);
    }

    public function assignMany(Term $term, array $sectionIds, mixed $instructorValue, ?int $actorId = null): Collection
    {
        $requestedIds = collect($sectionIds)->map(fn ($id) => (int) $id)->unique()->values();

        return DB::transaction(function () use ($term, $requestedIds, $instructorValue, $actorId) {
            $sections = Section::query()
                ->with(['offering', 'meetingBlocks'])
                ->whereIn('id', $requestedIds)
                ->get();
            if ($sections->count() !== $requestedIds->count()
                || $sections->contains(fn (Section $section) => (int) $section->offering->term_id !== (int) $term->id)) {
                throw ValidationException::withMessages([
                    'section_ids' => 'One or more sections do not belong to the requested term.',
                ]);
            }

            $assignment = $this->assignmentFrom(['instructor_id' => $instructorValue]);
            $this->validateReassignment($term, $sections, $assignment);

            foreach ($sections as $section) {
                $before = $this->snapshot($section);
                $section->update($assignment);
                $this->log($term, $section, $before, $this->snapshot($section), $actorId);
            }

            return $sections->fresh(['instructor']);
        }, 3);
    }

    private function assignmentFrom(array $attributes): array
    {
        $value = $attributes['instructor_id'] ?? null;
        $isStaff = $value === Section::STAFF_ASSIGNMENT || (bool) ($attributes['instructor_is_staff'] ?? false);
        $instructorId = $isStaff || empty($value) ? null : (int) $value;

        if ($instructorId !== null && ! Instructor::query()->whereKey($instructorId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['instructor_id' => 'An active instructor is required.']);
        }

        return [
            'instructor_id' => $instructorId,
            'instructor_is_staff' => $isStaff,
        ];
    }

    private function validateReassignment(Term $term, iterable $sections, array $assignment): void
    {
        if ($assignment['instructor_id'] === null) {
            return;
        }

        $messages = [];
        $selectedBlocks = collect();
        foreach ($sections as $section) {
            if ((int) $section->instructor_id === (int) $assignment['instructor_id'] && ! $section->instructor_is_staff) {
                continue;
            }

            $section->loadMissing('offering.catalogCourse', 'meetingBlocks');
            foreach ($section->meetingBlocks as $block) {
                $selectedBlocks->push([
                    'block' => $block,
                    'label' => trim($section->offering?->catalogCourse?->code.' '.$section->section_code),
                ]);
                $conflicts = $this->conflicts->instructorConflictsForMeetingBlock(
                    $term,
                    $assignment['instructor_id'],
                    ScheduleConflictService::normalizeDays($block->days_json ?? []),
                    (string) $block->starts_at,
                    (string) $block->ends_at,
                    $block->id,
                );
                if ($conflicts['meeting_blocks']->isNotEmpty() || $conflicts['office_hour_blocks']->isNotEmpty()) {
                    $messages[] = trim($section->offering?->catalogCourse?->code.' '.$section->section_code)
                        .' '.ScheduleConflictService::formatMeetingBlockLabel($block);
                }
            }
        }

        $buffer = (int) ($term->buffer_minutes ?? 0);
        for ($left = 0; $left < $selectedBlocks->count(); $left++) {
            for ($right = $left + 1; $right < $selectedBlocks->count(); $right++) {
                $a = $selectedBlocks[$left];
                $b = $selectedBlocks[$right];
                if (ScheduleConflictService::dayOverlap($a['block']->days_json ?? [], $b['block']->days_json ?? [])
                    && ScheduleConflictService::timesOverlap(
                        (string) $a['block']->starts_at,
                        (string) $a['block']->ends_at,
                        (string) $b['block']->starts_at,
                        (string) $b['block']->ends_at,
                        $buffer,
                    )) {
                    $messages[] = $a['label'].' and '.$b['label'].' overlap each other';
                }
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages([
                'instructor_id' => 'Instructor conflicts with scheduled work for: '.implode('; ', array_unique($messages)),
            ]);
        }
    }

    private function assertSectionInTerm(Term $term, Section $section): void
    {
        $section->loadMissing('offering');
        if ((int) $section->offering->term_id !== (int) $term->id) {
            throw ValidationException::withMessages(['section' => 'Section does not belong to the requested term.']);
        }
    }

    private function snapshot(Section $section): array
    {
        return $section->only([
            'section_code', 'instructor_id', 'instructor_is_staff', 'modality', 'notes',
            'section_capacity', 'enrolled_count',
        ]);
    }

    private function log(Term $term, Section $section, array $before, array $after, ?int $actorId): void
    {
        ScheduleChangeLog::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'meeting_block_id' => null,
            'user_id' => $actorId ?? auth()->id(),
            'action' => 'section_updated',
            'payload_before' => $before,
            'payload_after' => $after,
        ]);
    }
}

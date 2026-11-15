<?php

namespace App\Services;

use App\Data\Scheduling\OfficeHourCommand;
use App\Models\Instructor;
use App\Models\InstructorTermLock;
use App\Models\OfficeHourBlock;
use App\Models\ScheduleChangeLog;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficeHourMutationService
{
    public function __construct(
        private readonly ScheduleConflictService $conflicts,
        private readonly ScheduleEventSyncService $scheduleEvents,
    ) {}

    public function create(Term $term, Instructor $instructor, OfficeHourCommand $command, ?int $actorId = null): OfficeHourBlock
    {
        return DB::transaction(function () use ($term, $instructor, $command, $actorId) {
            $attributes = $command->attributes();
            $this->assertMutable($term, $instructor);
            $payload = $this->validatedPayload($term, $instructor, $attributes);
            $block = OfficeHourBlock::withoutEvents(fn () => OfficeHourBlock::query()->create([
                'term_id' => $term->id,
                'instructor_id' => $instructor->id,
                ...$payload,
            ]));
            $this->scheduleEvents->syncOfficeHourBlock($block);
            $this->log($term, 'created_office_hour', null, $this->snapshot($block), $actorId);

            return $block->fresh('instructor');
        }, 3);
    }

    public function update(Term $term, Instructor $instructor, OfficeHourBlock $block, OfficeHourCommand $command, ?int $actorId = null): OfficeHourBlock
    {
        $this->assertOwnership($term, $instructor, $block);

        return DB::transaction(function () use ($term, $instructor, $block, $command, $actorId) {
            $attributes = $command->attributes();
            $this->assertMutable($term, $instructor);
            $before = $this->snapshot($block);
            $payload = $this->validatedPayload($term, $instructor, $attributes, $block->id);
            $block->fill($payload);
            $this->scheduleEvents->syncOfficeHourBlock($block);
            OfficeHourBlock::withoutEvents(fn () => $block->save());
            $this->log($term, 'updated_office_hour', $before, $this->snapshot($block), $actorId);

            return $block->fresh('instructor');
        }, 3);
    }

    public function delete(Term $term, Instructor $instructor, OfficeHourBlock $block, ?int $actorId = null): void
    {
        $this->assertOwnership($term, $instructor, $block);

        DB::transaction(function () use ($term, $instructor, $block, $actorId) {
            $this->assertMutable($term, $instructor);
            $before = $this->snapshot($block);
            $this->scheduleEvents->removeForSource($block);
            OfficeHourBlock::withoutEvents(fn () => $block->delete());
            $this->log($term, 'deleted_office_hour', $before, null, $actorId);
        }, 3);
    }

    private function assertMutable(Term $term, Instructor $instructor): void
    {
        if (! $instructor->is_active) {
            throw ValidationException::withMessages(['instructor' => 'An active instructor is required.']);
        }

        $locked = InstructorTermLock::query()
            ->whereBelongsTo($term)
            ->whereBelongsTo($instructor)
            ->where('office_hours_locked', true)
            ->exists();
        if ($locked) {
            throw ValidationException::withMessages([
                'office_hours' => 'Office hours are locked for this instructor in the requested term.',
            ]);
        }
    }

    private function assertOwnership(Term $term, Instructor $instructor, OfficeHourBlock $block): void
    {
        if ((int) $block->term_id !== (int) $term->id || (int) $block->instructor_id !== (int) $instructor->id) {
            throw ValidationException::withMessages([
                'office_hour' => 'Office hour block does not belong to the requested term and instructor.',
            ]);
        }
    }

    private function validatedPayload(Term $term, Instructor $instructor, array $attributes, ?int $ignoreBlockId = null): array
    {
        $days = ScheduleConflictService::normalizeDays($attributes['days'] ?? $attributes['days_json'] ?? []);
        $startsAt = substr((string) ($attributes['starts_at'] ?? ''), 0, 5);
        $endsAt = substr((string) ($attributes['ends_at'] ?? ''), 0, 5);

        $errors = [];
        if ($days === []) {
            $errors['days'] = 'At least one meeting day is required.';
        }
        if ($endsAt <= $startsAt) {
            $errors['ends_at'] = 'End time must be after start time.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $conflicts = $this->conflicts->instructorConflictsForOfficeHourBlock(
            $term,
            $instructor->id,
            $days,
            $startsAt,
            $endsAt,
            $ignoreBlockId,
        );
        $messages = [];
        if ($conflicts['office_hour_blocks']->isNotEmpty()) {
            $messages[] = 'Conflicts with existing office hours: '.$conflicts['office_hour_blocks']
                ->map(fn (OfficeHourBlock $block) => ScheduleConflictService::formatOfficeHourLabel($block))
                ->implode('; ');
        }
        if ($conflicts['meeting_blocks']->isNotEmpty()) {
            $messages[] = 'Conflicts with class meeting blocks: '.$conflicts['meeting_blocks']
                ->map(fn ($block) => ScheduleConflictService::formatMeetingBlockLabel($block))
                ->implode('; ');
        }
        if ($messages !== []) {
            throw ValidationException::withMessages(['conflicts' => implode(' | ', $messages)]);
        }

        return [
            'days_json' => $days,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'notes' => $attributes['notes'] ?? null,
        ];
    }

    private function snapshot(OfficeHourBlock $block): array
    {
        return ['office_hour_block_id' => $block->id, ...$block->only([
            'instructor_id', 'days_json', 'starts_at', 'ends_at', 'notes',
        ])];
    }

    private function log(Term $term, string $action, ?array $before, ?array $after, ?int $actorId): void
    {
        ScheduleChangeLog::query()->create([
            'term_id' => $term->id,
            'section_id' => null,
            'user_id' => $actorId ?? auth()->id(),
            'action' => $action,
            'payload_before' => $before,
            'payload_after' => $after,
        ]);
    }
}

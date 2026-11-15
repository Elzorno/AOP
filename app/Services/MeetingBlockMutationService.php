<?php

namespace App\Services;

use App\Data\Scheduling\MeetingBlockCommand;
use App\Models\MeetingBlock;
use App\Models\ScheduleChangeLog;
use App\Models\ScheduledEvent;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeetingBlockMutationService
{
    public function __construct(
        private readonly ScheduleConflictService $conflicts,
        private readonly ScheduleRuleWarningService $ruleWarnings,
        private readonly ScheduleEventSyncService $scheduleEvents,
    ) {}

    public function preview(Term $term, Section $section, MeetingBlockCommand $command, ?MeetingBlock $meetingBlock = null): array
    {
        $attributes = $command->attributes();
        $section->loadMissing('offering', 'instructor');
        if ((int) $section->offering->term_id !== (int) $term->id) {
            throw ValidationException::withMessages([
                $meetingBlock ? 'blockId' : 'section' => $meetingBlock
                    ? 'Meeting block does not belong to the requested term.'
                    : 'Section does not belong to the requested term.',
            ]);
        }
        if ($meetingBlock && (int) $meetingBlock->section_id !== (int) $section->id) {
            throw ValidationException::withMessages([
                'blockId' => 'Meeting block does not belong to the requested section.',
            ]);
        }

        $days = ScheduleConflictService::normalizeDays(
            $attributes['days'] ?? $attributes['days_json'] ?? $meetingBlock?->days_json ?? [],
        );
        $startsAt = substr((string) ($attributes['starts_at'] ?? $meetingBlock?->starts_at ?? ''), 0, 5);
        $endsAt = substr((string) ($attributes['ends_at'] ?? $meetingBlock?->ends_at ?? ''), 0, 5);
        $roomId = $this->resolveRoomId($attributes, $meetingBlock?->room_id);
        $roomId = $this->validateSchedule(
            $term,
            $section,
            $days,
            $startsAt,
            $endsAt,
            $roomId,
            $meetingBlock?->id,
        );

        $blockType = (string) ($attributes['type'] ?? $meetingBlock?->type?->value ?? $meetingBlock?->type ?? 'OTHER');
        $findings = $this->ruleWarnings->findingsForBlock($days, $startsAt, $endsAt, $blockType, $term);
        $warnings = array_map(fn ($finding) => $finding->explanation, $findings);
        if ($warnings !== [] && $this->ruleWarnings->isEnforced($term)) {
            throw ValidationException::withMessages(['schedule_rules' => implode(' | ', $warnings)]);
        }

        return [
            'normalized' => [
                'days_json' => $days,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'room_id' => $roomId,
            ],
            'warnings' => $warnings,
            'policy_findings' => array_map(fn ($finding) => $finding->toArray(), $findings),
        ];
    }

    public function create(Term $term, Section $section, MeetingBlockCommand $command, ?int $actorId = null): MeetingBlock
    {
        return DB::transaction(function () use ($term, $section, $command, $actorId) {
            $attributes = $command->attributes();
            $schedule = $this->preview($term, $section, $command)['normalized'];

            $block = MeetingBlock::withoutEvents(fn () => MeetingBlock::query()->create([
                'section_id' => $section->id,
                'type' => $attributes['type'],
                ...$schedule,
                'notes' => $attributes['notes'] ?? null,
            ]));
            $this->scheduleEvents->syncMeetingBlock($block);

            $this->log($term, $section, $block, 'created', null, $this->snapshot($block), $actorId);

            return $block->fresh(['section.offering.catalogCourse', 'section.instructor', 'room']);
        }, 3);
    }

    public function update(Term $term, MeetingBlock $meetingBlock, MeetingBlockCommand $command, ?int $actorId = null): MeetingBlock
    {
        return DB::transaction(function () use ($term, $meetingBlock, $command, $actorId) {
            $attributes = $command->attributes();
            $this->assertExpectedRevision($meetingBlock, $attributes['expected_revision'] ?? null);
            $before = $this->snapshot($meetingBlock);
            $updated = $this->performUpdate($term, $meetingBlock, $command);
            $this->log($term, $updated->section, $updated, 'updated', $before, $this->snapshot($updated), $actorId);

            return $updated;
        }, 3);
    }

    public function delete(Term $term, Section $section, MeetingBlock $meetingBlock, ?int $actorId = null): void
    {
        $section->loadMissing('offering');
        if ((int) $section->offering->term_id !== (int) $term->id
            || (int) $meetingBlock->section_id !== (int) $section->id) {
            throw ValidationException::withMessages([
                'blockId' => 'Meeting block does not belong to the requested term and section.',
            ]);
        }

        DB::transaction(function () use ($term, $section, $meetingBlock, $actorId) {
            $before = $this->snapshot($meetingBlock);
            $this->scheduleEvents->removeForSource($meetingBlock);
            MeetingBlock::withoutEvents(fn () => $meetingBlock->delete());
            $this->log($term, $section, null, 'deleted', $before, null, $actorId);
        }, 3);
    }

    private function performUpdate(Term $term, MeetingBlock $meetingBlock, MeetingBlockCommand $command): MeetingBlock
    {
        $attributes = $command->attributes();
        $meetingBlock->loadMissing('section.offering', 'section.instructor', 'room');
        $payload = $this->preview($term, $meetingBlock->section, $command, $meetingBlock)['normalized'];

        if (array_key_exists('type', $attributes)) {
            $payload['type'] = $attributes['type'];
        }
        if (array_key_exists('notes', $attributes)) {
            $payload['notes'] = $attributes['notes'];
        }

        $meetingBlock->fill($payload);
        $this->scheduleEvents->syncMeetingBlock($meetingBlock);
        MeetingBlock::withoutEvents(fn () => $meetingBlock->save());

        return $meetingBlock->fresh(['section.offering.catalogCourse', 'section.instructor', 'room']);
    }

    private function resolveRoomId(array $attributes, ?int $fallback): ?int
    {
        if (array_key_exists('room_id', $attributes)) {
            return $attributes['room_id'] ? (int) $attributes['room_id'] : null;
        }

        if (array_key_exists('roomId', $attributes)) {
            return $attributes['roomId'] ? (int) $attributes['roomId'] : null;
        }

        return $fallback ? (int) $fallback : null;
    }

    private function assertExpectedRevision(MeetingBlock $meetingBlock, mixed $expectedRevision): void
    {
        if ($expectedRevision === null) {
            return;
        }

        $event = ScheduledEvent::query()
            ->where('source_type', MeetingBlock::class)
            ->where('source_id', $meetingBlock->id)
            ->first();
        if (! $event || (int) $event->revision !== (int) $expectedRevision) {
            throw ValidationException::withMessages([
                'revision' => 'This event changed after the calendar loaded. Refresh the calendar and try again.',
            ]);
        }
    }

    private function validateSchedule(
        Term $term,
        Section $section,
        array $days,
        string $startsAt,
        string $endsAt,
        ?int $roomId,
        ?int $ignoreMeetingBlockId = null,
    ): ?int {
        if ($days === []) {
            throw ValidationException::withMessages(['days' => 'At least one meeting day is required.']);
        }
        if ($endsAt <= $startsAt) {
            throw ValidationException::withMessages(['ends_at' => 'End time must be after start time.']);
        }

        $modality = (string) ($section->modality?->value ?? 'IN_PERSON');
        if ($modality === 'ONLINE') {
            $roomId = null;
        } elseif (empty($roomId)) {
            throw ValidationException::withMessages([
                'room_id' => 'Room is required for in-person or hybrid sections.',
            ]);
        }

        $roomConflicts = $this->conflicts->roomConflictsForMeetingBlock(
            $term,
            (int) ($roomId ?? 0),
            $days,
            $startsAt,
            $endsAt,
            $ignoreMeetingBlockId,
        );
        $roomConflictLabels = ScheduleConflictService::formatRoomConflictMessages($roomConflicts);
        if ($roomConflictLabels !== []) {
            throw ValidationException::withMessages([
                'conflicts' => 'Room conflict with: '.implode('; ', $roomConflictLabels),
            ]);
        }

        if (! empty($section->instructor_id)) {
            $conflicts = $this->conflicts->instructorConflictsForMeetingBlock(
                $term,
                (int) $section->instructor_id,
                $days,
                $startsAt,
                $endsAt,
                $ignoreMeetingBlockId,
            );
            $messages = [];
            if ($conflicts['meeting_blocks']->isNotEmpty()) {
                $messages[] = 'Instructor conflict with classes: '.$conflicts['meeting_blocks']
                    ->map(fn ($block) => ScheduleConflictService::formatMeetingBlockLabel($block))
                    ->implode('; ');
            }
            if ($conflicts['office_hour_blocks']->isNotEmpty()) {
                $messages[] = 'Instructor conflict with office hours: '.$conflicts['office_hour_blocks']
                    ->map(fn ($block) => ScheduleConflictService::formatOfficeHourLabel($block))
                    ->implode('; ');
            }
            if ($messages !== []) {
                throw ValidationException::withMessages(['conflicts' => implode(' | ', $messages)]);
            }
        }

        return $roomId;
    }

    private function snapshot(MeetingBlock $meetingBlock): array
    {
        return $meetingBlock->only(['type', 'days_json', 'starts_at', 'ends_at', 'room_id', 'notes']);
    }

    private function log(
        Term $term,
        Section $section,
        ?MeetingBlock $meetingBlock,
        string $action,
        ?array $before,
        ?array $after,
        ?int $actorId,
    ): void {
        ScheduleChangeLog::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'meeting_block_id' => $meetingBlock?->id,
            'user_id' => $actorId ?? auth()->id(),
            'action' => $action,
            'payload_before' => $before,
            'payload_after' => $after,
        ]);
    }
}

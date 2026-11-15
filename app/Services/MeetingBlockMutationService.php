<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\ScheduleChangeLog;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeetingBlockMutationService
{
    public function __construct(private readonly ScheduleConflictService $conflicts) {}

    public function create(Term $term, Section $section, array $attributes, ?int $actorId = null): MeetingBlock
    {
        $section->loadMissing('offering', 'instructor');
        if ((int) $section->offering->term_id !== (int) $term->id) {
            throw ValidationException::withMessages([
                'section' => 'Section does not belong to the requested term.',
            ]);
        }

        return DB::transaction(function () use ($term, $section, $attributes, $actorId) {
            $days = ScheduleConflictService::normalizeDays($attributes['days'] ?? $attributes['days_json'] ?? []);
            $startsAt = substr((string) ($attributes['starts_at'] ?? ''), 0, 5);
            $endsAt = substr((string) ($attributes['ends_at'] ?? ''), 0, 5);
            $roomId = $this->resolveRoomId($attributes, null);
            $roomId = $this->validateSchedule($term, $section, $days, $startsAt, $endsAt, $roomId);

            $block = MeetingBlock::query()->create([
                'section_id' => $section->id,
                'type' => $attributes['type'],
                'days_json' => $days,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'room_id' => $roomId,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->log($term, $section, $block, 'created', null, $this->snapshot($block), $actorId);

            return $block->fresh(['section.offering.catalogCourse', 'section.instructor', 'room']);
        });
    }

    public function update(Term $term, MeetingBlock $meetingBlock, array $attributes, ?int $actorId = null): MeetingBlock
    {
        return DB::transaction(function () use ($term, $meetingBlock, $attributes, $actorId) {
            $before = $this->snapshot($meetingBlock);
            $updated = $this->performUpdate($term, $meetingBlock, $attributes);
            $this->log($term, $updated->section, $updated, 'updated', $before, $this->snapshot($updated), $actorId);

            return $updated;
        });
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
            $meetingBlock->delete();
            $this->log($term, $section, null, 'deleted', $before, null, $actorId);
        });
    }

    private function performUpdate(Term $term, MeetingBlock $meetingBlock, array $attributes): MeetingBlock
    {
        $meetingBlock->loadMissing('section.offering', 'section.instructor', 'room');

        if ((int) $meetingBlock->section->offering->term_id !== (int) $term->id) {
            throw ValidationException::withMessages([
                'blockId' => 'Meeting block does not belong to the requested term.',
            ]);
        }

        $days = $attributes['days'] ?? ($attributes['days_json'] ?? $meetingBlock->days_json ?? []);
        $days = ScheduleConflictService::normalizeDays($days);
        if (count($days) === 0) {
            throw ValidationException::withMessages([
                'days' => 'At least one meeting day is required.',
            ]);
        }

        $startsAt = (string) ($attributes['starts_at'] ?? $meetingBlock->starts_at);
        $endsAt = (string) ($attributes['ends_at'] ?? $meetingBlock->ends_at);
        $startsAt = substr($startsAt, 0, 5);
        $endsAt = substr($endsAt, 0, 5);

        if ($endsAt <= $startsAt) {
            throw ValidationException::withMessages([
                'ends_at' => 'End time must be after start time.',
            ]);
        }

        $roomId = $this->resolveRoomId($attributes, $meetingBlock->room_id);
        $modality = (string) ($meetingBlock->section->modality?->value ?? 'IN_PERSON');

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
            $meetingBlock->id,
        );

        $roomConflictLabels = ScheduleConflictService::formatRoomConflictMessages($roomConflicts);
        if ($roomConflictLabels !== []) {
            throw ValidationException::withMessages([
                'conflicts' => 'Room conflict with: '.implode('; ', $roomConflictLabels),
            ]);
        }

        if (! empty($meetingBlock->section->instructor_id)) {
            $insConflicts = $this->conflicts->instructorConflictsForMeetingBlock(
                $term,
                (int) $meetingBlock->section->instructor_id,
                $days,
                $startsAt,
                $endsAt,
                $meetingBlock->id,
            );

            $messages = [];
            if ($insConflicts['meeting_blocks']->count() > 0) {
                $messages[] = 'Instructor conflict with classes: '.$insConflicts['meeting_blocks']
                    ->map(fn ($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))
                    ->implode('; ');
            }
            if ($insConflicts['office_hour_blocks']->count() > 0) {
                $messages[] = 'Instructor conflict with office hours: '.$insConflicts['office_hour_blocks']
                    ->map(fn ($ob) => ScheduleConflictService::formatOfficeHourLabel($ob))
                    ->implode('; ');
            }

            if (! empty($messages)) {
                throw ValidationException::withMessages([
                    'conflicts' => implode(' | ', $messages),
                ]);
            }
        }

        $payload = [
            'days_json' => $days,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'room_id' => $roomId,
        ];

        if (array_key_exists('type', $attributes)) {
            $payload['type'] = $attributes['type'];
        }
        if (array_key_exists('notes', $attributes)) {
            $payload['notes'] = $attributes['notes'];
        }

        $meetingBlock->update($payload);

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

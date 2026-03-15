<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\Term;
use Illuminate\Validation\ValidationException;

class MeetingBlockMutationService
{
    public function __construct(private readonly ScheduleConflictService $conflicts)
    {
    }

    public function update(Term $term, MeetingBlock $meetingBlock, array $attributes): MeetingBlock
    {
        $meetingBlock->loadMissing('section.offering', 'section.instructor', 'room');

        if ((int) $meetingBlock->section->offering->term_id !== (int) $term->id) {
            throw ValidationException::withMessages([
                'blockId' => 'Meeting block does not belong to the active term.',
            ]);
        }

        if ((bool) $term->schedule_locked) {
            throw ValidationException::withMessages([
                'term' => 'Schedule is locked for the active term. Unlock it before making schedule changes.',
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

        if ($roomConflicts->count() > 0) {
            throw ValidationException::withMessages([
                'conflicts' => 'Room conflict with: '.$roomConflicts
                    ->map(fn ($mb) => ScheduleConflictService::formatMeetingBlockLabel($mb))
                    ->implode('; '),
            ]);
        }

        if (!empty($meetingBlock->section->instructor_id)) {
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

            if (!empty($messages)) {
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
}
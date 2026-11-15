<?php

namespace App\Data\Scheduling;

final readonly class MeetingBlockCommand
{
    public function __construct(
        public ?string $type,
        public ?array $days,
        public ?string $startsAt,
        public ?string $endsAt,
        public ?int $roomId,
        public bool $roomProvided,
        public ?string $notes,
        public bool $notesProvided,
        public ?int $expectedRevision,
    ) {}

    public static function fromArray(array $attributes): self
    {
        $roomProvided = array_key_exists('room_id', $attributes) || array_key_exists('roomId', $attributes);
        $room = $attributes['room_id'] ?? $attributes['roomId'] ?? null;

        return new self(
            type: isset($attributes['type']) ? (string) $attributes['type'] : null,
            days: isset($attributes['days']) || isset($attributes['days_json'])
                ? array_values((array) ($attributes['days'] ?? $attributes['days_json']))
                : null,
            startsAt: isset($attributes['starts_at']) ? (string) $attributes['starts_at'] : null,
            endsAt: isset($attributes['ends_at']) ? (string) $attributes['ends_at'] : null,
            roomId: $room === null || $room === '' ? null : (int) $room,
            roomProvided: $roomProvided,
            notes: isset($attributes['notes']) ? (string) $attributes['notes'] : null,
            notesProvided: array_key_exists('notes', $attributes),
            expectedRevision: isset($attributes['expected_revision']) ? (int) $attributes['expected_revision'] : null,
        );
    }

    public function attributes(): array
    {
        $attributes = [];
        foreach ([
            'type' => $this->type,
            'days' => $this->days,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'expected_revision' => $this->expectedRevision,
        ] as $key => $value) {
            if ($value !== null) {
                $attributes[$key] = $value;
            }
        }
        if ($this->roomProvided) {
            $attributes['room_id'] = $this->roomId;
        }
        if ($this->notesProvided) {
            $attributes['notes'] = $this->notes;
        }

        return $attributes;
    }
}

<?php

namespace App\Data\Scheduling;

final readonly class OpenLabCommand
{
    public function __construct(
        public string $name,
        public array $days,
        public string $startsAt,
        public string $endsAt,
        public ?int $roomId,
        public ?int $labTechId,
        public ?string $notes,
    ) {}

    public static function fromArray(array $attributes): self
    {
        return new self(
            name: (string) ($attributes['name'] ?? ''),
            days: array_values((array) ($attributes['days'] ?? $attributes['days_json'] ?? [])),
            startsAt: (string) ($attributes['starts_at'] ?? ''),
            endsAt: (string) ($attributes['ends_at'] ?? ''),
            roomId: empty($attributes['room_id']) ? null : (int) $attributes['room_id'],
            labTechId: empty($attributes['lab_tech_id']) ? null : (int) $attributes['lab_tech_id'],
            notes: isset($attributes['notes']) ? (string) $attributes['notes'] : null,
        );
    }

    public function attributes(): array
    {
        return ['name' => $this->name, 'days' => $this->days, 'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt, 'room_id' => $this->roomId, 'lab_tech_id' => $this->labTechId,
            'notes' => $this->notes];
    }
}

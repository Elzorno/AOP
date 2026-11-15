<?php

namespace App\Data\Scheduling;

final readonly class OfficeHourCommand
{
    public function __construct(
        public array $days,
        public string $startsAt,
        public string $endsAt,
        public ?string $notes,
    ) {}

    public static function fromArray(array $attributes): self
    {
        return new self(
            days: array_values((array) ($attributes['days'] ?? $attributes['days_json'] ?? [])),
            startsAt: (string) ($attributes['starts_at'] ?? ''),
            endsAt: (string) ($attributes['ends_at'] ?? ''),
            notes: isset($attributes['notes']) ? (string) $attributes['notes'] : null,
        );
    }

    public function attributes(): array
    {
        return ['days' => $this->days, 'starts_at' => $this->startsAt, 'ends_at' => $this->endsAt, 'notes' => $this->notes];
    }
}

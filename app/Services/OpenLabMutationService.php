<?php

namespace App\Services;

use App\Models\LabTech;
use App\Models\OpenLab;
use App\Models\Room;
use App\Models\ScheduleChangeLog;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenLabMutationService
{
    public function __construct(
        private readonly ScheduleConflictService $conflicts,
        private readonly LabTechWorkloadService $workloads,
    ) {}

    public function create(Term $term, array $attributes, ?int $actorId = null): OpenLab
    {
        return DB::transaction(function () use ($term, $attributes, $actorId) {
            $payload = $this->validatedPayload($term, $attributes);
            $openLab = OpenLab::query()->create(['term_id' => $term->id, ...$payload]);
            $this->log($term, 'created_open_lab', null, $this->snapshot($openLab), $actorId);

            return $openLab->fresh(['room', 'labTech']);
        }, 3);
    }

    public function update(Term $term, OpenLab $openLab, array $attributes, ?int $actorId = null): OpenLab
    {
        if ((int) $openLab->term_id !== (int) $term->id) {
            throw ValidationException::withMessages(['open_lab' => 'Open lab does not belong to the requested term.']);
        }

        return DB::transaction(function () use ($term, $openLab, $attributes, $actorId) {
            $before = $this->snapshot($openLab);
            $payload = $this->validatedPayload($term, $attributes, $openLab->id);
            $openLab->update($payload);
            $this->log($term, 'updated_open_lab', $before, $this->snapshot($openLab), $actorId);

            return $openLab->fresh(['room', 'labTech']);
        }, 3);
    }

    public function delete(Term $term, OpenLab $openLab, ?int $actorId = null): void
    {
        if ((int) $openLab->term_id !== (int) $term->id) {
            throw ValidationException::withMessages(['open_lab' => 'Open lab does not belong to the requested term.']);
        }

        DB::transaction(function () use ($term, $openLab, $actorId) {
            $before = $this->snapshot($openLab);
            $openLab->delete();
            $this->log($term, 'deleted_open_lab', $before, null, $actorId);
        }, 3);
    }

    private function validatedPayload(Term $term, array $attributes, ?int $ignoreOpenLabId = null): array
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        $days = ScheduleConflictService::normalizeDays($attributes['days'] ?? $attributes['days_json'] ?? []);
        $startsAt = substr((string) ($attributes['starts_at'] ?? ''), 0, 5);
        $endsAt = substr((string) ($attributes['ends_at'] ?? ''), 0, 5);
        $room = Room::query()->where('is_active', true)->find($attributes['room_id'] ?? null);
        $labTech = LabTech::query()->where('is_active', true)->find($attributes['lab_tech_id'] ?? null);

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'An open lab name is required.';
        }
        if ($days === []) {
            $errors['days'] = 'At least one meeting day is required.';
        }
        if ($endsAt <= $startsAt) {
            $errors['ends_at'] = 'End time must be after start time.';
        }
        if (! $room) {
            $errors['room_id'] = 'An active room is required.';
        }
        if (! $labTech) {
            $errors['lab_tech_id'] = 'An active lab tech is required.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $roomMessages = ScheduleConflictService::formatRoomConflictMessages(
            $this->conflicts->roomConflictsForOpenLab(
                $term,
                $room->id,
                $days,
                $startsAt,
                $endsAt,
                $ignoreOpenLabId,
            )
        );
        if ($roomMessages !== []) {
            throw ValidationException::withMessages(['conflicts' => 'Room conflict with: '.implode('; ', $roomMessages)]);
        }

        $labTechConflicts = $this->conflicts->labTechConflictsForOpenLab(
            $term,
            $labTech->id,
            $days,
            $startsAt,
            $endsAt,
            $ignoreOpenLabId,
        );
        if ($labTechConflicts->isNotEmpty()) {
            throw ValidationException::withMessages([
                'conflicts' => 'Lab tech conflict with: '.$labTechConflicts
                    ->map(fn (OpenLab $conflict) => ScheduleConflictService::formatOpenLabLabel($conflict))
                    ->implode('; '),
            ]);
        }

        $projectedMinutes = $this->workloads->assignedMinutesForLabTech($term, $labTech, $ignoreOpenLabId)
            + ScheduleConflictService::weeklyMinutes($days, $startsAt, $endsAt);
        $limitMinutes = (int) round(((float) $labTech->weekly_hour_limit) * 60);
        if ($projectedMinutes > $limitMinutes) {
            throw ValidationException::withMessages([
                'lab_tech_id' => sprintf(
                    '%s would be scheduled for %.2f weekly hours, above the HR limit of %.2f.',
                    $labTech->name,
                    $projectedMinutes / 60,
                    (float) $labTech->weekly_hour_limit,
                ),
            ]);
        }

        return [
            'name' => $name,
            'room_id' => $room->id,
            'lab_tech_id' => $labTech->id,
            'days_json' => $days,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'notes' => $attributes['notes'] ?? null,
        ];
    }

    private function snapshot(OpenLab $openLab): array
    {
        return ['open_lab_id' => $openLab->id, ...$openLab->only([
            'name', 'room_id', 'lab_tech_id', 'days_json', 'starts_at', 'ends_at', 'notes',
        ])];
    }

    private function log(Term $term, string $action, ?array $before, ?array $after, ?int $actorId): void
    {
        ScheduleChangeLog::query()->create([
            'term_id' => $term->id,
            'user_id' => $actorId ?? auth()->id(),
            'action' => $action,
            'payload_before' => $before,
            'payload_after' => $after,
        ]);
    }
}

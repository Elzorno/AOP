<?php

namespace App\Services;

use App\Models\ScheduledEvent;
use App\Models\Term;
use Illuminate\Support\Collection;

class ScheduleEventQueryService
{
    public function forTerm(Term $term): Collection
    {
        return ScheduledEvent::query()
            ->with(['room', 'staffAssignments'])
            ->where('term_id', $term->id)
            ->orderBy('starts_at')
            ->orderBy('display_name')
            ->get();
    }

    public function roomConflicts(Term $term, int $roomId, array $days, string $startsAt, string $endsAt, ?int $ignoreEventId = null): Collection
    {
        $query = ScheduledEvent::query()
            ->where('term_id', $term->id)
            ->where('room_id', $roomId);

        if ($ignoreEventId) {
            $query->where('id', '!=', $ignoreEventId);
        }

        $buffer = (int) $term->buffer_minutes;

        return $query->get()->filter(function (ScheduledEvent $event) use ($days, $startsAt, $endsAt, $buffer) {
            return ScheduleConflictService::dayOverlap($days, $event->days_json ?? [])
                && ScheduleConflictService::timesOverlap($startsAt, $endsAt, $event->starts_at, $event->ends_at, $buffer);
        })->values();
    }

    public function staffConflicts(Term $term, string $staffType, int $staffId, array $days, string $startsAt, string $endsAt, ?int $ignoreEventId = null): Collection
    {
        $query = ScheduledEvent::query()
            ->where('term_id', $term->id)
            ->whereHas('staffAssignments', fn ($assignments) => $assignments
                ->where($staffType === 'instructor' ? 'instructor_id' : 'lab_tech_id', $staffId));

        if ($ignoreEventId) {
            $query->where('id', '!=', $ignoreEventId);
        }

        $buffer = (int) $term->buffer_minutes;

        return $query->get()->filter(function (ScheduledEvent $event) use ($days, $startsAt, $endsAt, $buffer) {
            return ScheduleConflictService::dayOverlap($days, $event->days_json ?? [])
                && ScheduleConflictService::timesOverlap($startsAt, $endsAt, $event->starts_at, $event->ends_at, $buffer);
        })->values();
    }
}

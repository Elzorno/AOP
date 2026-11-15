<?php

namespace App\Services;

use App\Models\ScheduledEvent;
use App\Models\Term;
use App\Models\TermScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ScheduleOccurrenceService
{
    /** @return Collection<int, CarbonImmutable> */
    public function datesForEvent(Term $term, ScheduledEvent $event): Collection
    {
        if (! $term->starts_on || (int) $event->term_id !== (int) $term->id) {
            return collect();
        }

        $timezone = $term->schedule_timezone ?: 'UTC';
        $startsOn = CarbonImmutable::parse($term->starts_on->format('Y-m-d'), $timezone)->startOfDay();
        $endsOn = $term->ends_on
            ? CarbonImmutable::parse($term->ends_on->format('Y-m-d'), $timezone)->startOfDay()
            : $startsOn->addWeeks(max(1, (int) $term->weeks_in_term))->subDay();
        $meetingDays = collect($event->days_json ?? [])->map(
            fn ($day) => strtolower(substr((string) $day, 0, 3))
        )->unique();

        $dates = collect();
        for ($date = $startsOn; $date->lte($endsOn); $date = $date->addDay()) {
            if ($meetingDays->contains(strtolower($date->format('D')))) {
                $dates->push($date);
            }
        }

        $exceptions = TermScheduleException::query()
            ->where('term_id', $term->id)
            ->whereBetween('occurs_on', [$startsOn->toDateString(), $endsOn->toDateString()])
            ->where(function ($query) use ($event) {
                $query->whereNull('scheduled_event_id')->orWhere('scheduled_event_id', $event->id);
            })
            ->get();
        $cancelledDates = $exceptions
            ->whereIn('exception_type', [
                TermScheduleException::TYPE_HOLIDAY,
                TermScheduleException::TYPE_CANCELLATION,
            ])
            ->map(fn ($exception) => $exception->occurs_on->toDateString());
        $makeupDates = $exceptions
            ->where('exception_type', TermScheduleException::TYPE_MAKEUP)
            ->where('scheduled_event_id', $event->id)
            ->map(fn ($exception) => CarbonImmutable::parse($exception->occurs_on->format('Y-m-d'), $timezone));

        return $dates
            ->reject(fn (CarbonImmutable $date) => $cancelledDates->contains($date->toDateString()))
            ->concat($makeupDates)
            ->unique(fn (CarbonImmutable $date) => $date->toDateString())
            ->sort()
            ->values();
    }
}

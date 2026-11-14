<?php

namespace App\Services;

use App\Models\LabTech;
use App\Models\OpenLab;
use App\Models\ScheduledEvent;
use App\Models\Term;
use Illuminate\Support\Collection;

class LabTechWorkloadService
{
    public function summariesForTerm(Term $term, bool $activeOnly = false): Collection
    {
        $labTechs = LabTech::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        $eventsByTech = ScheduledEvent::query()
            ->where('term_id', $term->id)
            ->whereHas('staffAssignments', fn ($assignments) => $assignments->where('staff_type', 'lab_tech'))
            ->with(['staffAssignments' => fn ($assignments) => $assignments->where('staff_type', 'lab_tech')])
            ->get()
            ->flatMap(fn (ScheduledEvent $event) => $event->staffAssignments->map(fn ($assignment) => [
                'staff_id' => $assignment->staff_id,
                'event' => $event,
            ]))
            ->groupBy('staff_id');

        return $labTechs->map(function (LabTech $labTech) use ($eventsByTech) {
            $assignments = ($eventsByTech[$labTech->id] ?? collect())->values();
            $assignedMinutes = $assignments->sum(fn (array $assignment) => ScheduleConflictService::weeklyMinutes(
                $assignment['event']->days_json ?? [],
                (string) $assignment['event']->starts_at,
                (string) $assignment['event']->ends_at,
            ));

            $limitHours = (float) $labTech->weekly_hour_limit;
            $limitMinutes = (int) round($limitHours * 60);
            $overageMinutes = max(0, $assignedMinutes - $limitMinutes);

            return [
                'lab_tech' => $labTech,
                'open_lab_count' => $assignments->where('event.event_type', ScheduledEvent::TYPE_OPEN_LAB)->count(),
                'assigned_minutes' => $assignedMinutes,
                'assigned_hours' => $assignedMinutes / 60,
                'weekly_hour_limit' => $limitHours,
                'limit_minutes' => $limitMinutes,
                'remaining_hours' => ($limitMinutes - $assignedMinutes) / 60,
                'overage_minutes' => $overageMinutes,
                'is_over_limit' => $assignedMinutes > $limitMinutes,
            ];
        });
    }

    public function assignedMinutesForLabTech(Term $term, LabTech $labTech, ?int $ignoreOpenLabId = null): int
    {
        $query = ScheduledEvent::query()
            ->where('term_id', $term->id)
            ->whereHas('staffAssignments', fn ($assignments) => $assignments
                ->where('staff_type', 'lab_tech')
                ->where('staff_id', $labTech->id));

        if ($ignoreOpenLabId) {
            $query->where(function ($events) use ($ignoreOpenLabId) {
                $events->where('source_type', '!=', OpenLab::class)
                    ->orWhere('source_id', '!=', $ignoreOpenLabId);
            });
        }

        return $query->get()->sum(fn (ScheduledEvent $event) => ScheduleConflictService::weeklyMinutes(
            $event->days_json ?? [],
            (string) $event->starts_at,
            (string) $event->ends_at,
        ));
    }
}

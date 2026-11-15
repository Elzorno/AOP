<?php

namespace App\Services;

use App\Data\Scheduling\SchedulePolicyFinding;
use App\Models\Term;
use App\Policies\SchedulePolicy;

final class ScheduleReadinessFindingService
{
    public function build(Term $term, array $groups): array
    {
        $version = $term->schedule_policy_version ?: SchedulePolicy::COMPATIBILITY_V1;
        $findings = collect($groups['distribution']['findings'] ?? []);

        foreach ($groups['missing_instructor'] as $section) {
            $findings->push($this->finding(
                $version,
                'readiness.section_missing_instructor',
                'structural_validity',
                'error',
                ['section:'.$section->id],
                'Section '.$section->section_code.' has no instructor or STAFF assignment.',
                route('aop.schedule.sections.edit', [$term, $section]),
            ));
        }
        foreach ($groups['missing_meetings'] as $section) {
            $findings->push($this->finding(
                $version,
                'readiness.section_missing_meeting',
                'structural_validity',
                'error',
                ['section:'.$section->id],
                'Section '.$section->section_code.' requires at least one meeting.',
                route('aop.schedule.sections.edit', [$term, $section]),
            ));
        }
        foreach ($groups['missing_rooms'] as $block) {
            $findings->push($this->finding(
                $version,
                'readiness.meeting_missing_room',
                'structural_validity',
                'error',
                ['event:'.$block->scheduled_event_id, 'meeting_block:'.$block->id],
                'A scheduled class meeting has no room.',
                route('aop.schedule.home', ['term' => $term, 'focus' => $block->section_id]),
            ));
        }

        foreach ($groups['room_conflicts'] as $conflict) {
            $findings->push($this->finding(
                $version,
                'conflict.room_overlap',
                'resource_conflict',
                'error',
                ['room:'.($conflict['room']?->id ?? 'unknown')],
                $conflict['a_label'].' conflicts with '.$conflict['b_label'].'.',
                $conflict['action_url'] ?? null,
            ));
        }
        foreach ($groups['instructor_conflicts'] as $conflict) {
            $findings->push($this->finding(
                $version,
                'conflict.instructor_overlap',
                'resource_conflict',
                'error',
                ['instructor:'.($conflict['instructor']?->id ?? 'unknown')],
                $conflict['a_label'].' conflicts with '.$conflict['b_label'].'.',
                isset($conflict['a_section_id']) && $conflict['a_section_id']
                    ? route('aop.schedule.home', ['term' => $term, 'focus' => $conflict['a_section_id']])
                    : null,
            ));
        }
        foreach ($groups['lab_tech_conflicts'] as $conflict) {
            $findings->push($this->finding(
                $version,
                'conflict.lab_tech_overlap',
                'resource_conflict',
                'error',
                ['lab_tech:'.($conflict['lab_tech']?->id ?? 'unknown')],
                $conflict['a_label'].' conflicts with '.$conflict['b_label'].'.',
                $conflict['action_url'] ?? null,
            ));
        }

        return $findings->values()->all();
    }

    private function finding(
        string $version,
        string $ruleId,
        string $category,
        string $severity,
        array $resources,
        string $explanation,
        ?string $remediationUrl,
    ): array {
        return (new SchedulePolicyFinding(
            ruleId: $ruleId,
            category: $category,
            severity: $severity,
            affectedResources: $resources,
            explanation: $explanation,
            remediationUrl: $remediationUrl,
            policyVersion: $version,
        ))->toArray();
    }
}

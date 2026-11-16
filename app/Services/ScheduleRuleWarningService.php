<?php

namespace App\Services;

use App\Data\Scheduling\SchedulePolicyFinding;
use App\Models\Term;
use App\Policies\SchedulePolicy;

/**
 * Validates a proposed meeting block against institutional scheduling rules
 * and returns human-readable warning strings.
 *
 * Rules source: "Summary of Proposed Class Scheduling Rules" PDF.
 *
 * When Term::enforce_schedule_blocks is true, callers should treat
 * warnings as hard validation errors. Otherwise they are advisory.
 */
class ScheduleRuleWarningService
{
    public function __construct(private readonly SchedulePolicyRegistry $policies) {}

    /**
     * Returns whether rule warnings should be treated as hard errors
     * for the given term.
     */
    public function isEnforced(Term $term): bool
    {
        return (bool) ($term->enforce_schedule_blocks ?? false);
    }

    /**
     * Evaluate a proposed block and return an array of warning strings.
     * An empty array means no rule violations detected.
     *
     * @param  array       $days       e.g. ['Mon','Wed','Fri']
     * @param  string      $startsAt   HH:MM (24-hour)
     * @param  string      $endsAt     HH:MM (24-hour)
     * @param  string      $blockType  'LECTURE' | 'LAB' | 'OTHER'
     * @param  Term|null   $term       optional, used for context-sensitive checks
     */
    public function warnForBlock(
        array $days,
        string $startsAt,
        string $endsAt,
        string $blockType,
        ?Term $term = null
    ): array {
        return array_map(
            fn (SchedulePolicyFinding $finding) => $finding->explanation,
            $this->findingsForBlock($days, $startsAt, $endsAt, $blockType, $term),
        );
    }

    /** @return list<SchedulePolicyFinding> */
    public function findingsForBlock(
        array $days,
        string $startsAt,
        string $endsAt,
        string $blockType,
        ?Term $term = null,
    ): array {
        $policy = $term ? $this->policies->forTerm($term) : new SchedulePolicy(SchedulePolicy::COMPATIBILITY_V1);
        $policyVersion = $policy->version;
        $severity = $term && $this->isEnforced($term) ? 'error' : 'warning';

        return array_map(
            fn (string $message) => new SchedulePolicyFinding(
                ruleId: $this->ruleId($message),
                category: 'institutional_policy',
                severity: $severity,
                affectedResources: [],
                explanation: $message,
                remediationUrl: null,
                policyVersion: $policyVersion,
            ),
            $this->warningMessagesForBlock(
                $days,
                $startsAt,
                $endsAt,
                $blockType,
                $policy->meetingWindowRules(),
            ),
        );
    }

    private function warningMessagesForBlock(
        array $days,
        string $startsAt,
        string $endsAt,
        string $blockType,
        array $rules,
    ): array {
        $warnings = [];

        $days    = array_values(array_unique($days));
        $count   = count($days);
        $startMin = ScheduleBlockLibrary::toMinutes($startsAt);

        // OTHER blocks are unstructured – skip canonical checks
        if ($blockType === 'OTHER') {
            return [];
        }

        // ----------------------------------------------------------------
        // Rule: 2-day MW/MF/WF courses should start at 2:00 pm or later
        // ----------------------------------------------------------------
        if ($count === 2) {
            $sig = ScheduleBlockLibrary::makeSig($days);
            if (
                in_array($sig, ScheduleBlockLibrary::TWO_DAY_AFTERNOON_PATTERNS, true) &&
                $startMin < self::toMin($rules['two_day_afternoon_start'])
            ) {
                $warnings[] = 'Two-day ' . implode('/', $days) . ' courses should start at 2:00 pm or later.';
            }
        }

        // ----------------------------------------------------------------
        // Rule: 1-day-per-week courses should start at 5:00 pm or later
        // ----------------------------------------------------------------
        if ($count === 1 && $startMin < self::toMin($rules['evening_start'])) {
            $warnings[] = 'One-day-per-week courses should start at 5:00 pm or later.';
        }

        // ----------------------------------------------------------------
        // Rule: standard meeting hours are 8:00 am – 6:30 pm (non-evening)
        // Evening courses start at 5:00 pm or later.
        // ----------------------------------------------------------------
        if ($startMin < self::toMin($rules['standard_open'])) {
            $warnings[] = 'Class start time is before the standard 8:00 am opening.';
        }

        $endMin = ScheduleBlockLibrary::toMinutes($endsAt);
        if ($endMin > self::toMin($rules['latest_close'])) {
            $warnings[] = 'Class end time exceeds the 10:50 pm closing limit.';
        }

        // ----------------------------------------------------------------
        // Rule: MW 3:30 pm – 5:00 pm is reserved for campus faculty meetings
        // Flag any block that meets on Monday or Wednesday and overlaps this window.
        // ----------------------------------------------------------------
        $campusMeetingStart = self::toMin($rules['faculty_meeting_start']);
        $campusMeetingEnd   = self::toMin($rules['faculty_meeting_end']);
        $hasMWDay = !empty(array_intersect($days, ['Mon', 'Wed']));
        if ($hasMWDay) {
            $overlap = max(0, min($endMin, $campusMeetingEnd) - max($startMin, $campusMeetingStart));
            if ($overlap > 0) {
                $warnings[] = 'Monday/Wednesday 3:30–5:00 pm is reserved for campus faculty meetings. This block overlaps that window.';
            }
        }

        // ----------------------------------------------------------------
        // Rule: standard day hours end at 6:30 pm; flag non-evening blocks
        // that run past 18:30. (Evening courses starting at 5:00 pm+ are exempt.)
        // ----------------------------------------------------------------
        if ($startMin < self::toMin($rules['evening_start']) && $endMin > self::toMin($rules['day_course_close'])) {
            $warnings[] = 'This block ends after the 6:30 pm standard close for day courses. Consider scheduling as an evening course (5:00 pm start) instead.';
        }

        // ----------------------------------------------------------------
        // Rule: start time should match a canonical block start time
        // ----------------------------------------------------------------
        $canonicalStarts = ScheduleBlockLibrary::canonicalStartsFor($days, $blockType);

        if (!empty($canonicalStarts)) {
            $s = substr($startsAt, 0, 5);
            if (!in_array($s, $canonicalStarts, true)) {
                $pattern = implode('/', $days);
                $warnings[] = sprintf(
                    'Start time %s is not a standard block start for %s %s. Standard starts: %s.',
                    $s,
                    $pattern,
                    $blockType,
                    implode(', ', $canonicalStarts)
                );
            } else {
                // ----------------------------------------------------------------
                // Rule: duration should match the canonical end for this start
                // ----------------------------------------------------------------
                $canonicalEnd = ScheduleBlockLibrary::canonicalEndFor($days, $startsAt, $blockType);
                if ($canonicalEnd !== null) {
                    $e = substr($endsAt, 0, 5);
                    if ($e !== $canonicalEnd) {
                        $canonicalDur = ScheduleBlockLibrary::durationMinutes(substr($startsAt, 0, 5), $canonicalEnd);
                        $actualDur    = ScheduleBlockLibrary::durationMinutes(substr($startsAt, 0, 5), substr($endsAt, 0, 5));
                        $warnings[] = sprintf(
                            'Duration %d min does not match the standard %d min block ending at %s for this day pattern.',
                            $actualDur,
                            $canonicalDur,
                            $canonicalEnd
                        );
                    }
                }
            }
        }

        return $warnings;
    }

    private function ruleId(string $message): string
    {
        return match (true) {
            str_starts_with($message, 'Two-day ') => 'class.two_day_afternoon_start',
            str_starts_with($message, 'One-day-per-week ') => 'class.one_day_evening_start',
            str_starts_with($message, 'Class start time ') => 'class.standard_opening_time',
            str_starts_with($message, 'Class end time ') => 'class.latest_closing_time',
            str_starts_with($message, 'Monday/Wednesday ') => 'class.faculty_meeting_window',
            str_starts_with($message, 'This block ends after ') => 'class.day_course_closing_time',
            str_starts_with($message, 'Start time ') => 'class.canonical_start_time',
            str_starts_with($message, 'Duration ') => 'class.canonical_duration',
            default => 'class.unclassified_policy_rule',
        };
    }

    // -----------------------------------------------------------------------

    private static function toMin(string $hhmm): int
    {
        return ScheduleBlockLibrary::toMinutes($hhmm);
    }
}

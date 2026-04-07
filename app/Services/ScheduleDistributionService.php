<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\Section;
use App\Models\Term;

/**
 * Computes term-level schedule distribution metrics.
 *
 * Rules source: "Summary of Proposed Class Scheduling Rules" PDF.
 *
 * Targets (informational only — not enforced as hard gates):
 *  - Min 12% of sections with at least one Friday meeting
 *  - Max 60% of total classroom minutes between 9:30 am – 3:00 pm M–F
 *  - Max 60% of total classroom minutes on M/W/F days
 *  - Max 60% of total classroom minutes on T/R days
 */
class ScheduleDistributionService
{
    private const PEAK_START = '09:30';
    private const PEAK_END   = '15:00';

    /** @return array{friday_pct: float, peak_hour_pct: float, mwf_pct: float, tr_pct: float, totals: array} */
    public function compute(Term $term): array
    {
        $blocks = MeetingBlock::query()
            ->whereHas('section.offering', fn ($q) => $q->where('term_id', $term->id))
            ->get();

        $totalSections = Section::query()
            ->whereHas('offering', fn ($q) => $q->where('term_id', $term->id))
            ->count();

        if ($blocks->isEmpty() || $totalSections === 0) {
            return $this->emptyResult();
        }

        // Minutes contributed per block = duration × number of days it meets
        $totalMinutes   = 0;
        $peakMinutes    = 0;
        $mwfMinutes     = 0;
        $trMinutes      = 0;

        $peakStartMin = ScheduleBlockLibrary::toMinutes(self::PEAK_START);
        $peakEndMin   = ScheduleBlockLibrary::toMinutes(self::PEAK_END);

        $mwfDays = ['Mon', 'Wed', 'Fri'];
        $trDays  = ['Tue', 'Thu'];

        foreach ($blocks as $block) {
            $days    = $block->days_json ?? [];
            $dur     = ScheduleBlockLibrary::durationMinutes(
                substr((string) $block->starts_at, 0, 5),
                substr((string) $block->ends_at, 0, 5)
            );

            foreach ($days as $day) {
                $totalMinutes += $dur;

                // Peak-hour overlap: block must overlap the 9:30–15:00 window
                $blockStart = ScheduleBlockLibrary::toMinutes(substr((string) $block->starts_at, 0, 5));
                $blockEnd   = ScheduleBlockLibrary::toMinutes(substr((string) $block->ends_at, 0, 5));
                $overlap    = max(0, min($blockEnd, $peakEndMin) - max($blockStart, $peakStartMin));
                $peakMinutes += $overlap;

                if (in_array($day, $mwfDays, true)) {
                    $mwfMinutes += $dur;
                }
                if (in_array($day, $trDays, true)) {
                    $trMinutes += $dur;
                }
            }
        }

        // Friday coverage: % of sections that have at least one Friday meeting
        $sectionIdsWithFriday = $blocks
            ->filter(fn ($b) => in_array('Fri', $b->days_json ?? [], true))
            ->pluck('section_id')
            ->unique()
            ->count();

        $fridayPct    = $totalSections > 0 ? round(($sectionIdsWithFriday / $totalSections) * 100, 1) : 0.0;
        $peakPct      = $totalMinutes > 0  ? round(($peakMinutes / $totalMinutes) * 100, 1) : 0.0;
        $mwfPct       = $totalMinutes > 0  ? round(($mwfMinutes / $totalMinutes) * 100, 1) : 0.0;
        $trPct        = $totalMinutes > 0  ? round(($trMinutes / $totalMinutes) * 100, 1) : 0.0;

        return [
            'friday_pct'   => $fridayPct,
            'peak_hour_pct'=> $peakPct,
            'mwf_pct'      => $mwfPct,
            'tr_pct'       => $trPct,
            'totals' => [
                'sections'              => $totalSections,
                'sections_with_friday'  => $sectionIdsWithFriday,
                'total_minutes'         => $totalMinutes,
                'peak_minutes'          => $peakMinutes,
                'mwf_minutes'           => $mwfMinutes,
                'tr_minutes'            => $trMinutes,
            ],
            'targets' => [
                'friday_min_pct'    => 12.0,
                'peak_max_pct'      => 60.0,
                'mwf_max_pct'       => 60.0,
                'tr_max_pct'        => 60.0,
            ],
            'flags' => [
                'friday_low'  => $fridayPct < 12.0 && $totalSections > 0,
                'peak_high'   => $peakPct > 60.0,
                'mwf_high'    => $mwfPct > 60.0,
                'tr_high'     => $trPct > 60.0,
            ],
        ];
    }

    private function emptyResult(): array
    {
        return [
            'friday_pct'    => 0.0,
            'peak_hour_pct' => 0.0,
            'mwf_pct'       => 0.0,
            'tr_pct'        => 0.0,
            'totals' => [
                'sections'             => 0,
                'sections_with_friday' => 0,
                'total_minutes'        => 0,
                'peak_minutes'         => 0,
                'mwf_minutes'          => 0,
                'tr_minutes'           => 0,
            ],
            'targets' => [
                'friday_min_pct' => 12.0,
                'peak_max_pct'   => 60.0,
                'mwf_max_pct'    => 60.0,
                'tr_max_pct'     => 60.0,
            ],
            'flags' => [
                'friday_low' => false,
                'peak_high'  => false,
                'mwf_high'   => false,
                'tr_high'    => false,
            ],
        ];
    }
}

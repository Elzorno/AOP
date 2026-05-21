<?php

namespace App\Services;

use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\Section;
use App\Models\Term;
use App\Enums\SectionModality;

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

    /** @return array{friday_pct: float, peak_hour_pct: float, mwf_pct: float, tr_pct: float, gep_online_pct: float, program_online_pct: float, totals: array} */
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

        $fridayPct = $totalSections > 0 ? round(($sectionIdsWithFriday / $totalSections) * 100, 1) : 0.0;
        $peakPct   = $totalMinutes > 0  ? round(($peakMinutes / $totalMinutes) * 100, 1) : 0.0;
        $mwfPct    = $totalMinutes > 0  ? round(($mwfMinutes / $totalMinutes) * 100, 1) : 0.0;
        $trPct     = $totalMinutes > 0  ? round(($trMinutes / $totalMinutes) * 100, 1) : 0.0;

        // Online % for GEP and program-required sections
        [$gepOnlinePct, $gepTotal, $gepOnline] = $this->onlinePctForFlag($term, 'is_gep');
        [$progOnlinePct, $progTotal, $progOnline] = $this->onlinePctForFlag($term, 'is_program_required');

        return [
            'friday_pct'       => $fridayPct,
            'peak_hour_pct'    => $peakPct,
            'mwf_pct'          => $mwfPct,
            'tr_pct'           => $trPct,
            'gep_online_pct'   => $gepOnlinePct,
            'program_online_pct' => $progOnlinePct,
            'totals' => [
                'sections'              => $totalSections,
                'sections_with_friday'  => $sectionIdsWithFriday,
                'total_minutes'         => $totalMinutes,
                'peak_minutes'          => $peakMinutes,
                'mwf_minutes'           => $mwfMinutes,
                'tr_minutes'            => $trMinutes,
                'gep_total'             => $gepTotal,
                'gep_online'            => $gepOnline,
                'program_total'         => $progTotal,
                'program_online'        => $progOnline,
            ],
            'targets' => [
                'friday_min_pct'       => 12.0,
                'peak_max_pct'         => 60.0,
                'mwf_max_pct'          => 60.0,
                'tr_max_pct'           => 60.0,
                'gep_online_max_pct'   => 40.0,
                'program_online_max_pct' => 25.0,
            ],
            'flags' => [
                'friday_low'       => $fridayPct < 12.0 && $totalSections > 0,
                'peak_high'        => $peakPct > 60.0,
                'mwf_high'         => $mwfPct > 60.0,
                'tr_high'          => $trPct > 60.0,
                'gep_online_high'  => $gepOnlinePct > 40.0,
                'prog_online_high' => $progOnlinePct > 25.0,
            ],
        ];
    }

    /**
     * Compute distribution impact of adding a hypothetical block with the given
     * days/time to the active term.
     *
     * Returns the same shape as compute() but with the hypothetical block counted.
     */
    public function previewBlock(Term $term, array $days, string $startsAt, string $endsAt): array
    {
        if (empty($days) || $startsAt === '' || $endsAt === '' || $endsAt <= $startsAt) {
            return $this->compute($term);
        }

        // Compute base stats, then inject the hypothetical block contribution
        $base = $this->compute($term);

        $peakStartMin = ScheduleBlockLibrary::toMinutes(self::PEAK_START);
        $peakEndMin   = ScheduleBlockLibrary::toMinutes(self::PEAK_END);
        $mwfDays      = ['Mon', 'Wed', 'Fri'];
        $trDays       = ['Tue', 'Thu'];

        $dur = ScheduleBlockLibrary::durationMinutes(
            substr($startsAt, 0, 5),
            substr($endsAt, 0, 5)
        );

        $addTotal = 0;
        $addPeak  = 0;
        $addMwf   = 0;
        $addTr    = 0;
        $addFri   = in_array('Fri', $days, true);

        $blockStart = ScheduleBlockLibrary::toMinutes(substr($startsAt, 0, 5));
        $blockEnd   = ScheduleBlockLibrary::toMinutes(substr($endsAt, 0, 5));

        foreach ($days as $day) {
            $addTotal += $dur;
            $overlap   = max(0, min($blockEnd, $peakEndMin) - max($blockStart, $peakStartMin));
            $addPeak  += $overlap;
            if (in_array($day, $mwfDays, true)) $addMwf += $dur;
            if (in_array($day, $trDays, true))  $addTr  += $dur;
        }

        $newTotal   = $base['totals']['total_minutes'] + $addTotal;
        $newPeak    = $base['totals']['peak_minutes']  + $addPeak;
        $newMwf     = $base['totals']['mwf_minutes']   + $addMwf;
        $newTr      = $base['totals']['tr_minutes']    + $addTr;

        // Friday: a new section with Fri increases numerator by 1
        $newTotalSections     = $base['totals']['sections'] + 1;
        $newSectionsWithFriday = $base['totals']['sections_with_friday'] + ($addFri ? 1 : 0);

        $fridayPct = $newTotalSections > 0 ? round(($newSectionsWithFriday / $newTotalSections) * 100, 1) : 0.0;
        $peakPct   = $newTotal > 0  ? round(($newPeak / $newTotal) * 100, 1) : 0.0;
        $mwfPct    = $newTotal > 0  ? round(($newMwf  / $newTotal) * 100, 1) : 0.0;
        $trPct     = $newTotal > 0  ? round(($newTr   / $newTotal) * 100, 1) : 0.0;

        return array_merge($base, [
            'friday_pct'    => $fridayPct,
            'peak_hour_pct' => $peakPct,
            'mwf_pct'       => $mwfPct,
            'tr_pct'        => $trPct,
            'totals'        => array_merge($base['totals'], [
                'sections'              => $newTotalSections,
                'sections_with_friday'  => $newSectionsWithFriday,
                'total_minutes'         => $newTotal,
                'peak_minutes'          => $newPeak,
                'mwf_minutes'           => $newMwf,
                'tr_minutes'            => $newTr,
            ]),
            'flags' => [
                'friday_low'       => $fridayPct < 12.0,
                'peak_high'        => $peakPct > 60.0,
                'mwf_high'         => $mwfPct > 60.0,
                'tr_high'          => $trPct > 60.0,
                'gep_online_high'  => $base['flags']['gep_online_high'],
                'prog_online_high' => $base['flags']['prog_online_high'],
            ],
        ]);
    }

    /**
     * Compute the % of sections flagged with a given catalog_course boolean that are ONLINE.
     * Returns [pct, total_flagged_sections, online_flagged_sections].
     */
    private function onlinePctForFlag(Term $term, string $flag): array
    {
        $flaggedSections = Section::query()
            ->whereHas('offering', function ($q) use ($term, $flag) {
                $q->where('term_id', $term->id)
                  ->whereHas('catalogCourse', fn ($cq) => $cq->where($flag, true));
            })
            ->get(['id', 'modality']);

        $total  = $flaggedSections->count();
        $online = $flaggedSections->filter(
            fn ($s) => ($s->modality?->value ?? $s->modality) === SectionModality::ONLINE->value
        )->count();

        $pct = $total > 0 ? round(($online / $total) * 100, 1) : 0.0;

        return [$pct, $total, $online];
    }

    private function emptyResult(): array
    {
        return [
            'friday_pct'         => 0.0,
            'peak_hour_pct'      => 0.0,
            'mwf_pct'            => 0.0,
            'tr_pct'             => 0.0,
            'gep_online_pct'     => 0.0,
            'program_online_pct' => 0.0,
            'totals' => [
                'sections'             => 0,
                'sections_with_friday' => 0,
                'total_minutes'        => 0,
                'peak_minutes'         => 0,
                'mwf_minutes'          => 0,
                'tr_minutes'           => 0,
                'gep_total'            => 0,
                'gep_online'           => 0,
                'program_total'        => 0,
                'program_online'       => 0,
            ],
            'targets' => [
                'friday_min_pct'         => 12.0,
                'peak_max_pct'           => 60.0,
                'mwf_max_pct'            => 60.0,
                'tr_max_pct'             => 60.0,
                'gep_online_max_pct'     => 40.0,
                'program_online_max_pct' => 25.0,
            ],
            'flags' => [
                'friday_low'       => false,
                'peak_high'        => false,
                'mwf_high'         => false,
                'tr_high'          => false,
                'gep_online_high'  => false,
                'prog_online_high' => false,
            ],
        ];
    }
}

<?php

namespace App\Policies;

final readonly class SchedulePolicy
{
    public const COMPATIBILITY_V1 = 'compatibility-v1';

    public function __construct(public string $version) {}

    public function distributionTargets(): array
    {
        return [
            'friday_min_pct' => 12.0,
            'peak_max_pct' => 60.0,
            'mwf_max_pct' => 60.0,
            'tr_max_pct' => 60.0,
            'gep_online_max_pct' => 40.0,
            'program_online_max_pct' => 25.0,
        ];
    }
}

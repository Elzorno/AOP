<?php

namespace App\Services;

use App\Models\Term;
use App\Policies\SchedulePolicy;
use DomainException;

final class SchedulePolicyRegistry
{
    public function forTerm(Term $term): SchedulePolicy
    {
        $version = $term->schedule_policy_version ?: SchedulePolicy::COMPATIBILITY_V1;
        if ($version !== SchedulePolicy::COMPATIBILITY_V1) {
            throw new DomainException("Unsupported schedule policy version: {$version}");
        }

        return new SchedulePolicy($version);
    }
}

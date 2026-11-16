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

    public function instructionalMinuteRules(): array
    {
        return [
            'lecture_minutes_per_credit_15_weeks' => 750,
            'lab_minutes_per_credit_15_weeks' => 2250,
            'base_weeks' => 15,
        ];
    }

    public function fullTimeOfficeHourRules(): array
    {
        return ['minutes_per_week' => 240, 'distinct_days' => 3];
    }

    public function meetingWindowRules(): array
    {
        return [
            'two_day_afternoon_start' => '14:00',
            'evening_start' => '17:00',
            'standard_open' => '08:00',
            'latest_close' => '22:50',
            'faculty_meeting_start' => '15:30',
            'faculty_meeting_end' => '17:00',
            'day_course_close' => '18:30',
        ];
    }
}

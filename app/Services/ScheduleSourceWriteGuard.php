<?php

namespace App\Services;

use LogicException;

final class ScheduleSourceWriteGuard
{
    private static bool $enforced = true;

    public static function disableForTestFixtures(): void
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('The schedule source write guard can only be disabled for tests.');
        }

        self::$enforced = false;
    }

    public static function enable(): void
    {
        self::$enforced = true;
    }

    public static function assertExplicitMutation(): void
    {
        if (self::$enforced) {
            throw new LogicException(
                'Direct legacy schedule writes are disabled. Use the scheduling mutation service.'
            );
        }
    }
}

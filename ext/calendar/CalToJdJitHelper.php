<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * cal_to_jd() for compiled JIT/AOT modules (#27366, php-in-PHP).
 *
 * SSOT: {@see VmCalendar::calToJd()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(cal_to_jd)
 */
final class CalToJdJitHelper
{
    public static function calToJdArgv(int $calendar, int $month, int $day, int $year): int
    {
        if ($calendar < 0 || $calendar >= CalendarConstants::CAL_NUM_CALS) {
            throw new \ValueError(
                'cal_to_jd(): Argument #1 ($calendar) must be a valid calendar ID'
            );
        }
        if ($month > \PHP_INT_MAX - 1) {
            throw new \ValueError(
                \sprintf('cal_to_jd(): Argument #2 ($month) must be between 1 and %d', \PHP_INT_MAX - 1)
            );
        }
        if ($year > \PHP_INT_MAX - 1) {
            throw new \ValueError(
                \sprintf('cal_to_jd(): Argument #4 ($year) must be less than %d', \PHP_INT_MAX - 1)
            );
        }

        return VmCalendar::calToJd($calendar, $month, $day, $year);
    }
}

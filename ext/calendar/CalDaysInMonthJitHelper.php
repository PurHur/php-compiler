<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * cal_days_in_month() for compiled JIT/AOT modules (#27310, php-in-PHP).
 *
 * SSOT: {@see VmCalendar::calDaysInMonth()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(cal_days_in_month)
 */
final class CalDaysInMonthJitHelper
{
    public static function calDaysInMonthArgv(int $calendar, int $month, int $year): int
    {
        if ($calendar < 0 || $calendar >= CalendarConstants::CAL_NUM_CALS) {
            throw new \ValueError(
                'cal_days_in_month(): Argument #1 ($calendar) must be a valid calendar ID'
            );
        }
        // php-src calendar.c does not ValueError on month<=0 — SDN helpers throw Invalid date.
        if ($month > \PHP_INT_MAX - 1) {
            throw new \ValueError(
                \sprintf('cal_days_in_month(): Argument #2 ($month) must be between 1 and %d', \PHP_INT_MAX - 1)
            );
        }
        if ($year > \PHP_INT_MAX - 1) {
            throw new \ValueError(
                \sprintf('cal_days_in_month(): Argument #3 ($year) must be less than %d', \PHP_INT_MAX - 1)
            );
        }

        return VmCalendar::calDaysInMonth($calendar, $month, $year);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\VM\HashTable;

/**
 * cal_info() for compiled JIT/AOT modules (#27354, php-in-PHP).
 *
 * SSOT: {@see VmCalendar::calInfo()} / {@see VmCalendar::calInfoAll()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(cal_info)
 */
final class CalInfoJitHelper
{
    public static function calInfoArgv(int $calendar): HashTable
    {
        // php-src calendar.c — calendar == -1 is the all-calendars sentinel (#28907)
        if (-1 === $calendar) {
            return VmCalendar::calInfoAll();
        }
        if ($calendar < 0 || $calendar >= CalendarConstants::CAL_NUM_CALS) {
            throw new \ValueError(
                'cal_info(): Argument #1 ($calendar) must be a valid calendar ID'
            );
        }

        return VmCalendar::calInfo($calendar);
    }

    public static function calInfoAllArgv(): HashTable
    {
        return VmCalendar::calInfoAll();
    }
}

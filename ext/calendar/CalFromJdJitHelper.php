<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\VM\HashTable;

/**
 * cal_from_jd() for compiled JIT/AOT modules (#27359, php-in-PHP).
 *
 * SSOT: {@see VmCalendar::calFromJd()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(cal_from_jd)
 */
final class CalFromJdJitHelper
{
    public static function calFromJdArgv(int $julianDay, int $calendar): HashTable
    {
        if ($calendar < 0 || $calendar >= CalendarConstants::CAL_NUM_CALS) {
            throw new \ValueError(
                'cal_from_jd(): Argument #2 ($calendar) must be a valid calendar ID'
            );
        }

        return VmCalendar::calFromJd($julianDay, $calendar);
    }
}

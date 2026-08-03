<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * gregoriantojd() for compiled JIT/AOT modules (#27386, php-in-PHP).
 *
 * SSOT: {@see VmCalendar::gregorianToJd()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(gregoriantojd)
 */
final class GregoriantojdJitHelper
{
    public static function gregoriantojdArgv(int $month, int $day, int $year): int
    {
        return VmCalendar::gregorianToJd($month, $day, $year);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * jdmonthname() for compiled JIT/AOT modules (#27360, php-in-PHP).
 *
 * SSOT: {@see VmCalendar::jdMonthName()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(jdmonthname)
 */
final class JdmonthnameJitHelper
{
    public static function jdmonthnameArgv(int $julianDay, int $mode): string
    {
        return VmCalendar::jdMonthName($julianDay, $mode);
    }
}

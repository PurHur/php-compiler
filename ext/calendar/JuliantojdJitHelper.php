<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * juliantojd() for compiled JIT/AOT modules (#27384, php-in-PHP).
 *
 * SSOT: {@see VmJewishFrenchCalendar::juliantojd()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(juliantojd)
 */
final class JuliantojdJitHelper
{
    public static function juliantojdArgv(int $month, int $day, int $year): int
    {
        return VmJewishFrenchCalendar::juliantojd($month, $day, $year);
    }
}

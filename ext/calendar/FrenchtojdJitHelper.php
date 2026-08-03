<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * frenchtojd() for compiled JIT/AOT modules (#27382, php-in-PHP).
 *
 * SSOT: {@see VmJewishFrenchCalendar::frenchtojd()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(frenchtojd)
 */
final class FrenchtojdJitHelper
{
    public static function frenchtojdArgv(int $month, int $day, int $year): int
    {
        return VmJewishFrenchCalendar::frenchtojd($month, $day, $year);
    }
}

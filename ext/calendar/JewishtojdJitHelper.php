<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * jewishtojd() for compiled JIT/AOT modules (#27357, php-in-PHP).
 *
 * SSOT: {@see VmJewishFrenchCalendar::jewishtojd()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(jewishtojd)
 */
final class JewishtojdJitHelper
{
    public static function jewishtojdArgv(int $month, int $day, int $year): int
    {
        return VmJewishFrenchCalendar::jewishtojd($month, $day, $year);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * jdtofrench() for compiled JIT/AOT modules (#27383, php-in-PHP).
 *
 * SSOT: {@see VmJewishFrenchCalendar::jdtofrench()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(jdtofrench)
 */
final class JdtofrenchJitHelper
{
    public static function jdtofrenchArgv(int $julianDay): string
    {
        return VmJewishFrenchCalendar::jdtofrench($julianDay);
    }
}

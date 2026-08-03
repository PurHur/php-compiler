<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * jdtojulian() for compiled JIT/AOT modules (#27388, php-in-PHP).
 *
 * SSOT: {@see VmCalendar::jdtojulian()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(jdtojulian)
 */
final class JdtojulianJitHelper
{
    public static function jdtojulianArgv(int $julianDay): string
    {
        return VmCalendar::jdtojulian($julianDay);
    }
}

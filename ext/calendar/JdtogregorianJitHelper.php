<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * jdtogregorian() for compiled JIT/AOT modules (#27355, php-in-PHP).
 *
 * SSOT: {@see VmCalendar::jdtogregorian()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(jdtogregorian)
 */
final class JdtogregorianJitHelper
{
    public static function jdtogregorianArgv(int $julianDay): string
    {
        return VmCalendar::jdtogregorian($julianDay);
    }
}

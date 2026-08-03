<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * jdtounix() for compiled JIT/AOT modules (#27387, php-in-PHP).
 *
 * SSOT: {@see VmCalendar::jdtounix()}
 * php-src: ext/calendar/cal_unix.c — PHP_FUNCTION(jdtounix)
 */
final class JdtounixJitHelper
{
    public static function jdtounixArgv(int $julianDay): int
    {
        return VmCalendar::jdtounix($julianDay);
    }
}

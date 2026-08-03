<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * jdtojewish() for compiled JIT/AOT modules (#27368, php-in-PHP).
 *
 * SSOT: {@see VmJewishFrenchCalendar::jdtojewish()}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(jdtojewish)
 */
final class JdtojewishJitHelper
{
    public static function jdtojewishArgv(int $julianDay, int $mode = 0): string
    {
        return VmJewishFrenchCalendar::jdtojewish($julianDay, $mode);
    }
}

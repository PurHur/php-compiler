<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\JIT\CalendarExtensionHooks;
use PHPCompiler\VM\HashTable;

/**
 * calendar surfaces for lib/JIT Builtin CalInfo / CalFromJd (#36204).
 *
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(cal_info) / PHP_FUNCTION(cal_from_jd).
 * Registered from {@see Module::jitInit} so Builtin files do not import ext/calendar.
 */
final class JitCalendarExtensionHooksFacade implements CalendarExtensionHooks
{
    public function calInfoArgv(int $calendar): HashTable
    {
        return CalInfoJitHelper::calInfoArgv($calendar);
    }

    public function calInfoAllArgv(): HashTable
    {
        return CalInfoJitHelper::calInfoAllArgv();
    }

    public function calFromJdArgv(int $julianDay, int $calendar): HashTable
    {
        return CalFromJdJitHelper::calFromJdArgv($julianDay, $calendar);
    }
}

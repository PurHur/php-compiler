<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * CAL_* ids needed by lib/JIT Builtin lowering (#36204).
 *
 * Values match php-src ext/calendar/calendar.c register_calendar_symbols.
 * Full constant surface stays in {@see \PHPCompiler\ext\calendar\CalendarConstants}.
 */
final class CalendarIds
{
    public const CAL_NUM_CALS = 4;

    private function __construct()
    {
    }
}

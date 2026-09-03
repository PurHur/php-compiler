<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\HashTable;

/**
 * calendar extension surfaces needed by lib/JIT Builtin (#36204).
 *
 * Implemented in {@code ext/calendar/JitCalendarExtensionHooksFacade.php}; Builtin
 * CalInfo / CalFromJd files must not import {@code ext\calendar}.
 */
interface CalendarExtensionHooks
{
    /** Compile-time cal_info($calendar) meta table (php-src cal_info). */
    public function calInfoArgv(int $calendar): HashTable;

    /** Compile-time cal_info() with no args — all calendars. */
    public function calInfoAllArgv(): HashTable;

    /** Compile-time cal_from_jd($jd, $calendar) breakdown table. */
    public function calFromJdArgv(int $julianDay, int $calendar): HashTable;
}

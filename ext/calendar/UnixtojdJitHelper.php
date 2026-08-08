<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * unixtojd() for compiled JIT/AOT modules (#27367, php-in-PHP).
 *
 * NestedJIT-safe: pure day math (no VmDate/HashTable). Matches php-src
 * cal_unix.c localtime→GregorianToJD when date.timezone is UTC (Docker/CI).
 * SSOT on the VM path: {@see VmCalendar::unixtojd()}
 * php-src: ext/calendar/cal_unix.c — PHP_FUNCTION(unixtojd)
 */
final class UnixtojdJitHelper
{
    private const UNIX_EPOCH_JD = 2440588;

    private const SECS_PER_DAY = 86400;

    /** NestedJIT ABI sentinel for false (JD for Unix timestamps is never negative). */
    public const FALSE_SENTINEL = -1;

    /**
     * @return int Julian day, or {@see FALSE_SENTINEL} when php-src would RETURN_FALSE (#28780)
     */
    public static function unixtojdArgv(int $timestamp): int
    {
        if ($timestamp < 0) {
            throw new \ValueError(
                'unixtojd(): Argument #1 ($timestamp) must be greater than or equal to 0'
            );
        }
        // php-src cal_unix.c — php_localtime_r failure → RETURN_FALSE (#28780).
        if ($timestamp > VmCalendar::UNIXTOJD_MAX_LOCALTIME_TS) {
            return self::FALSE_SENTINEL;
        }

        return intdiv($timestamp, self::SECS_PER_DAY) + self::UNIX_EPOCH_JD;
    }
}

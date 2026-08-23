<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * easter_days() for compiled JIT/AOT modules (#27358).
 *
 * NestedJIT leaf — no throws (ValueError NestedJIT fails module verify with
 * "Referring to a basic block in another function" / throw_uncaught; blocks
 * helper-runtime refresh #24302). Invalid years return 0; VM wrapper still
 * validates before call.
 * php-src: ext/calendar/easter.c — PHP_FUNCTION(easter_days)
 */
final class EasterDaysJitHelper
{
    private const EASTER_ROMAN = 1;

    private const EASTER_ALWAYS_GREGORIAN = 2;

    private const EASTER_ALWAYS_JULIAN = 3;

    public static function easterDaysArgv(int $year, int $mode): int
    {
        if ($year <= 0) {
            return 0;
        }

        return self::easterDays($year, $mode);
    }

    public static function easterDaysNowArgv(int $mode): int
    {
        return self::easterDaysArgv(self::currentYear(), $mode);
    }

    public static function currentYear(): int
    {
        $days = intdiv(\time(), 86400);
        $z = $days + 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);

        return $yoe + $era * 400;
    }

    private static function easterDays(int $year, int $method): int
    {
        $golden = ($year % 19) + 1;

        if (($year <= 1582 && self::EASTER_ALWAYS_GREGORIAN !== $method)
            || ($year >= 1583 && $year <= 1752
                && self::EASTER_ROMAN !== $method
                && self::EASTER_ALWAYS_GREGORIAN !== $method)
            || self::EASTER_ALWAYS_JULIAN === $method) {
            $dom = ($year + intdiv($year, 4) + 5) % 7;
            if ($dom < 0) {
                $dom += 7;
            }
            $pfm = (3 - (11 * $golden) - 7) % 30;
            if ($pfm < 0) {
                $pfm += 30;
            }
        } else {
            $dom = ($year + intdiv($year, 4) - intdiv($year, 100) + intdiv($year, 400)) % 7;
            if ($dom < 0) {
                $dom += 7;
            }
            $solar = intdiv($year - 1600, 100) - intdiv($year - 1600, 400);
            $lunar = intdiv(intdiv($year - 1400, 100) * 8, 25);
            $pfm = (3 - (11 * $golden) + $solar - $lunar) % 30;
            if ($pfm < 0) {
                $pfm += 30;
            }
        }

        if (29 === $pfm || (28 === $pfm && $golden > 11)) {
            --$pfm;
        }

        $tmp = (4 - $pfm - $dom) % 7;
        if ($tmp < 0) {
            $tmp += 7;
        }

        return (int) ($pfm + $tmp + 1);
    }
}

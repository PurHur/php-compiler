<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * idate() for compiled JIT/AOT modules (#9181, #26900, php-in-PHP).
 *
 * NestedJIT-safe UTC civil math (no VmDate / host getdate). Prefer user-script
 * NestedJIT via HelperRuntimeCache USER_SCRIPT_INLINE_ONLY — helper-runtime unit.o
 * of this TU or of VmDate-linked idate returns wrong 0 / segfaults (#26900).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(idate)
 */
final class IdateJitHelper
{
    private const ERR_FORMAT = -1;

    private const ERR_TOKEN = -2;

    private const MSG_FORMAT_ONE_CHAR = 'idate(): idate format is one char';

    private const MSG_UNRECOGNIZED = 'idate(): Unrecognized date format token';

    public static function idate(string $format, int $timestamp): int
    {
        if (1 !== \strlen($format)) {
            $err = self::ERR_FORMAT;
            trigger_error(self::MSG_FORMAT_ONE_CHAR, \E_USER_WARNING);

            return $err;
        }

        $ch = $format[0];
        if ('U' === $ch) {
            return $timestamp;
        }

        $days = intdiv($timestamp, 86400);
        $rem = $timestamp - ($days * 86400);
        if ($rem < 0) {
            --$days;
            $rem += 86400;
        }
        $hour = intdiv($rem, 3600);
        $rem = $rem - ($hour * 3600);
        $minute = intdiv($rem, 60);
        $second = $rem - ($minute * 60);

        $ymd = self::civilYmdPacked($days);
        // Euclidean unpack — year < 0 must not use toward-zero intdiv (#31620).
        $day = $ymd % 100;
        $tmp = intdiv($ymd, 100);
        if ($day < 0) {
            $day += 100;
            --$tmp;
        }
        $month = $tmp % 100;
        $year = intdiv($tmp, 100);
        if ($month < 0) {
            $month += 100;
            --$year;
        }
        $wday = self::weekday($year, $month, $day);

        if ('d' === $ch || 'j' === $ch) {
            return $day;
        }
        if ('H' === $ch) {
            return $hour;
        }
        if ('i' === $ch) {
            return $minute;
        }
        if ('m' === $ch || 'n' === $ch) {
            return $month;
        }
        if ('s' === $ch) {
            return $second;
        }
        if ('w' === $ch) {
            return $wday;
        }
        if ('y' === $ch) {
            return $year % 100;
        }
        if ('Y' === $ch) {
            return $year;
        }

        $err = self::ERR_TOKEN;
        trigger_error(self::MSG_UNRECOGNIZED, \E_USER_WARNING);

        return $err;
    }

    private static function civilYmdPacked(int $days): int
    {
        $z = $days + 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp < 10 ? $mp + 3 : $mp - 9;
        if ($m <= 2) {
            ++$y;
        }

        return $y * 10000 + $m * 100 + $d;
    }

    private static function weekday(int $year, int $mon, int $mday): int
    {
        $y = $year;
        if ($mon < 3) {
            --$y;
        }
        $t = 4;
        if (1 === $mon) {
            $t = 0;
        } elseif (2 === $mon) {
            $t = 3;
        } elseif (3 === $mon) {
            $t = 2;
        } elseif (4 === $mon) {
            $t = 5;
        } elseif (5 === $mon) {
            $t = 0;
        } elseif (6 === $mon) {
            $t = 3;
        } elseif (7 === $mon) {
            $t = 5;
        } elseif (8 === $mon) {
            $t = 1;
        } elseif (9 === $mon) {
            $t = 4;
        } elseif (10 === $mon) {
            $t = 6;
        } elseif (11 === $mon) {
            $t = 2;
        }

        return (int) (($y + (int) ($y / 4) - (int) ($y / 100) + (int) ($y / 400) + $t + $mday) % 7);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * getdate() packed civil parts — host SSOT / tests (#9181, #26900).
 *
 * AOT/JIT lowering uses {@see JitGetdate} LLVM civil math (NestedJIT of this helper
 * segfaults on user-script AOT init). Keep algorithm aligned with JitGetdate IR.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getdate)
 */
final class GetdateJitHelper
{
    /** yyyymmdd */
    public static function ymdPacked(int $timestamp): int
    {
        $days = intdiv($timestamp, 86400);
        $rem = $timestamp - ($days * 86400);
        if ($rem < 0) {
            --$days;
        }

        return self::civilYmdPacked($days);
    }

    /** hour*10000 + minute*100 + second */
    public static function hmsPacked(int $timestamp): int
    {
        $days = intdiv($timestamp, 86400);
        $rem = $timestamp - ($days * 86400);
        if ($rem < 0) {
            $rem += 86400;
        }
        $hour = intdiv($rem, 3600);
        $rem = $rem - ($hour * 3600);
        $minute = intdiv($rem, 60);
        $second = $rem - ($minute * 60);

        return $hour * 10000 + $minute * 100 + $second;
    }

    /** wday*1000 + yday (0-based yday). */
    public static function wdayYdayPacked(int $timestamp): int
    {
        $days = intdiv($timestamp, 86400);
        $rem = $timestamp - ($days * 86400);
        if ($rem < 0) {
            --$days;
        }
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
        $yday = self::dayOfYear($year, $month, $day);

        return $wday * 1000 + $yday;
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

    private static function dayOfYear(int $year, int $mon, int $mday): int
    {
        $yday = $mday - 1;
        if ($mon > 1) {
            $yday += 31;
        }
        if ($mon > 2) {
            $leap = (0 === $year % 4 && 0 !== $year % 100) || 0 === $year % 400;
            $yday += $leap ? 29 : 28;
        }
        if ($mon > 3) {
            $yday += 31;
        }
        if ($mon > 4) {
            $yday += 30;
        }
        if ($mon > 5) {
            $yday += 31;
        }
        if ($mon > 6) {
            $yday += 30;
        }
        if ($mon > 7) {
            $yday += 31;
        }
        if ($mon > 8) {
            $yday += 31;
        }
        if ($mon > 9) {
            $yday += 30;
        }
        if ($mon > 10) {
            $yday += 31;
        }
        if ($mon > 11) {
            $yday += 30;
        }

        return $yday;
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

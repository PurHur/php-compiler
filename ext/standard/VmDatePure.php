<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP date/time libc replacements via host builtins (#13765, php-in-PHP).
 *
 * php-src: ext/standard/datetime.c — time, mktime, strptime, strftime, gettimeofday
 */
final class VmDatePure
{
    /** @var array{tm_sec:int,tm_min:int,tm_hour:int,tm_mday:int,tm_mon:int,tm_year:int,tm_wday:int,tm_yday:int,tm_isdst:int} */
    public static function tmPartsFromGetdate(array $d): array
    {
        return [
            'tm_sec' => (int) ($d['seconds'] ?? 0),
            'tm_min' => (int) ($d['minutes'] ?? 0),
            'tm_hour' => (int) ($d['hours'] ?? 0),
            'tm_mday' => (int) ($d['mday'] ?? 0),
            'tm_mon' => (int) ($d['mon'] ?? 1) - 1,
            'tm_year' => (int) ($d['year'] ?? 1970) - 1900,
            'tm_wday' => (int) ($d['wday'] ?? 0),
            'tm_yday' => (int) ($d['yday'] ?? 0),
            'tm_isdst' => (int) ($d['isdst'] ?? 0),
        ];
    }

    public static function available(): bool
    {
        return \function_exists('time') && \function_exists('getdate');
    }

    public static function time(): int
    {
        if (!\function_exists('time')) {
            return 0;
        }

        return (int) \time();
    }

    /** @return array{sec: int, usec: int} */
    public static function readTimeval(): array
    {
        if (\function_exists('gettimeofday')) {
            $tv = \gettimeofday();
            if (\is_array($tv)) {
                return [
                    'sec' => (int) ($tv['sec'] ?? 0),
                    'usec' => (int) ($tv['usec'] ?? 0),
                ];
            }
        }

        if (\function_exists('microtime')) {
            $parts = \explode(' ', (string) \microtime());

            return [
                'sec' => (int) ($parts[1] ?? 0),
                'usec' => (int) \round((float) ($parts[0] ?? 0) * 1_000_000),
            ];
        }

        return ['sec' => 0, 'usec' => 0];
    }

    /**
     * @return array{sec: int, usec: int, minuteswest: int, dsttime: int}
     */
    public static function gettimeofdayParts(): array
    {
        $tv = self::readTimeval();
        $minuteswest = 0;
        $dsttime = 0;
        if (\function_exists('date')) {
            $minuteswest = -(int) \round((int) \date('Z') / 60);
            $dsttime = (int) (0 !== (int) \date('I'));
        }

        return [
            'sec' => $tv['sec'],
            'usec' => $tv['usec'],
            'minuteswest' => $minuteswest,
            'dsttime' => $dsttime,
        ];
    }

    /**
     * Local civil breakdown from Unix timestamp.
     *
     * Must not call host {@see getdate()} — under thin AOT / NestedJIT that is missing or
     * circular with GetdateJitHelper (#26900). When host {@see date()} is available, apply
     * the active default-timezone offset so named zones (via
     * {@see VmDateTimeNative}::withTimezone / {@see pushProcessTimezone}) convert wall-clock
     * correctly (#27142; peer fixed-offset path uses gmtime(ts+offset)).
     * Without {@see date()}, fall back to UTC civil math (NestedJIT-safe).
     *
     * @return array{tm_sec:int,tm_min:int,tm_hour:int,tm_mday:int,tm_mon:int,tm_year:int,tm_wday:int,tm_yday:int,tm_isdst:int}|null
     */
    public static function localtime(int $timestamp): ?array
    {
        $offset = 0;
        if (\function_exists('date')) {
            $offset = (int) \date('Z', $timestamp);
        }

        return self::civilTmParts($timestamp + $offset);
    }

    /**
     * UTC civil breakdown from Unix timestamp (no host {@see gmdate()} — NestedJIT/AOT safe, #26900).
     *
     * @return array{tm_sec:int,tm_min:int,tm_hour:int,tm_mday:int,tm_mon:int,tm_year:int,tm_wday:int,tm_yday:int,tm_isdst:int}|null
     */
    public static function gmtime(int $timestamp): ?array
    {
        return self::civilTmParts($timestamp);
    }

    /**
     * @return array{tm_sec:int,tm_min:int,tm_hour:int,tm_mday:int,tm_mon:int,tm_year:int,tm_wday:int,tm_yday:int,tm_isdst:int}
     */
    private static function civilTmParts(int $timestamp): array
    {
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

        // Do not pack y*10000+m*100+d — toward-zero intdiv/mod breaks year < 0
        // (setISODate null → -0001-12-26 formatted as 0000--87--74, #31620).
        [$year, $month, $day] = self::civilYmd($days);
        $wday = self::civilWeekday($year, $month, $day);
        $yday = self::civilDayOfYear($year, $month, $day);

        return [
            'tm_sec' => $second,
            'tm_min' => $minute,
            'tm_hour' => $hour,
            'tm_mday' => $day,
            'tm_mon' => $month - 1,
            'tm_year' => $year - 1900,
            'tm_wday' => $wday,
            'tm_yday' => $yday,
            'tm_isdst' => 0,
        ];
    }

    /**
     * Civil Y-M-D from days since Unix epoch (Howard Hinnant).
     *
     * @return array{0: int, 1: int, 2: int} year, month (1–12), day
     */
    private static function civilYmd(int $days): array
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

        return [$y, $m, $d];
    }

    private static function civilDayOfYear(int $year, int $mon, int $mday): int
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

    /** Sakamoto — Sunday=0 … Saturday=6 (floor division for year < 0, #31620). */
    private static function civilWeekday(int $year, int $mon, int $mday): int
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

        $sum = $y + self::floorDiv($y, 4) - self::floorDiv($y, 100) + self::floorDiv($y, 400) + $t + $mday;

        return self::positiveMod($sum, 7);
    }

    /** Floor division (toward −∞) — PHP intdiv is toward zero. */
    private static function floorDiv(int $a, int $b): int
    {
        $q = intdiv($a, $b);
        $r = $a % $b;
        if (0 !== $r && (($a < 0) !== ($b < 0))) {
            --$q;
        }

        return $q;
    }

    private static function positiveMod(int $x, int $y): int
    {
        $tmp = $x % $y;
        if ($tmp < 0) {
            $tmp += $y;
        }

        return $tmp;
    }

    public static function mktime(
        int $hour,
        int $minute,
        int $second,
        int $month,
        int $day,
        int $year
    ): int|false {
        if (!\function_exists('mktime')) {
            return false;
        }
        $result = @\mktime($hour, $minute, $second, $month, $day, $year);

        return false === $result ? false : (int) $result;
    }

    public static function gmmktime(
        int $hour,
        int $minute,
        int $second,
        int $month,
        int $day,
        int $year
    ): int|false {
        if (!\function_exists('gmmktime')) {
            return false;
        }
        $result = @\gmmktime($hour, $minute, $second, $month, $day, $year);

        return false === $result ? false : (int) $result;
    }

    /** @return array<string, mixed>|false */
    public static function strptimeArray(string $date, string $format): array|false
    {
        // Prefer HashTable SSOT; flatten for rare array callers (#22771).
        $ht = StrptimeJitHelper::strptimeArgv($date, $format);
        if (false === $ht) {
            return false;
        }
        $out = [];
        foreach (['tm_sec', 'tm_min', 'tm_hour', 'tm_mday', 'tm_mon', 'tm_year', 'tm_wday', 'tm_yday'] as $key) {
            $v = $ht->find($key);
            if (null !== $v) {
                $out[$key] = $v->resolveIndirect()->toInt();
            }
        }
        $u = $ht->find('unparsed');
        if (null !== $u) {
            $out['unparsed'] = $u->resolveIndirect()->toString();
        }

        return $out;
    }

    public static function strftime(string $format, int $timestamp, bool $gmt): string|false
    {
        if ('' === $format) {
            return false;
        }
        if (!\function_exists('strftime')) {
            return '';
        }
        if ($gmt && \function_exists('gmstrftime')) {
            $text = @\gmstrftime($format, $timestamp);

            return \is_string($text) ? $text : false;
        }
        $text = @\strftime($format, $timestamp);

        return \is_string($text) ? $text : false;
    }

    /** Push default timezone for host date/mktime wrappers in VmDateTimeNative::withTimezone (#13857). */
    public static function pushProcessTimezone(string $tzName): string
    {
        $previous = \date_default_timezone_get();
        \date_default_timezone_set($tzName);

        return $previous;
    }

    public static function popProcessTimezone(string $previous): void
    {
        \date_default_timezone_set($previous);
    }
}

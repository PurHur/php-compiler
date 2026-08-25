<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * DateTime/DateTimeImmutable::format() for compiled JIT/AOT modules (#4043, #26772).
 *
 * NestedJIT-safe: no array returns, no by-ref outs, no `while (true)` civil walk,
 * no `$out .=` token loop. Those patterns heap-corrupt or miscompile under NestedJIT (#26772).
 *
 * php-src: ext/date/php_datetime.c — zim_DateTime_format
 */
final class DateTimeFormatJitHelper
{
    public static function formatStateArgv(string $format, int $timestamp, int $microsecond, string $tzName): string
    {
        // Bare T/e/O/P before civil offset adjust — formatTokensScalar NestedJIT aborts (#34610).
        // Peer DefaultTimezoneCivilJitHelper::formatTimezoneToken (#33956) for free date().
        if ('T' === $format || 'e' === $format || 'O' === $format || 'P' === $format) {
            return self::formatObjectTimezoneToken($format, $timestamp, $tzName);
        }

        $unixTs = $timestamp;
        $offset = self::parseNumericTimezoneOffsetSeconds($tzName);
        if (0 === $offset) {
            // Named IANA zones: apply active-zone offset when host date() exists (#27142).
            // NestedJIT without date() stays UTC civil (peer #26900).
            $offset = self::namedTimezoneOffsetSeconds($tzName, $timestamp);
            if (0 === $offset) {
                // NestedJIT AOT: host date() unavailable — use the same zone table as T/O/P (#34610).
                $offset = self::tableOffsetSeconds($tzName, $unixTs);
            }
        }
        if (0 !== $offset) {
            $timestamp += $offset;
        }

        if ('U' === $format) {
            return (string) $timestamp;
        }
        // php-src date.c — 'u' is zero-padded microseconds (#26936 createFromTimestamp float path).
        if ('U.u' === $format) {
            return (string) $timestamp.'.'.self::digits6($microsecond);
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

        // Packed yyyymmdd — NestedJIT rejects array/by-ref civil returns (#26772).
        // Unpack with Euclidean rem so year < 0 (e.g. -0001-12-26) is not 0000--87--74 (#31620).
        $ymd = self::civilYmdPacked($days);
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

        if ('Y-m-d' === $format) {
            return self::digits4($year).'-'.self::digits2($month).'-'.self::digits2($day);
        }
        if ('Y' === $format) {
            return self::digits4($year);
        }
        if ('m' === $format) {
            return self::digits2($month);
        }
        if ('d' === $format) {
            return self::digits2($day);
        }
        if ('H:i:s' === $format) {
            return self::digits2($hour).':'.self::digits2($minute).':'.self::digits2($second);
        }
        if ('Ymd' === $format) {
            return self::digits4($year).self::digits2($month).self::digits2($day);
        }
        // Composite wall + abbr — NestedJIT formatTokensScalar aborts (#34610).
        // Prefer stepwise concat (LLVM path also bakes this literal in DateTimeFormatJitHelper).
        if ('Y-m-d H:i:s T' === $format) {
            $ymd = self::digits4($year).'-'.self::digits2($month).'-'.self::digits2($day);
            $his = self::digits2($hour).':'.self::digits2($minute).':'.self::digits2($second);
            $abbr = self::formatObjectTimezoneToken('T', $unixTs, $tzName);

            return $ymd.' '.$his.' '.$abbr;
        }
        // en_US IntlDateFormatter SHORT date (ICU M/d/yy → PHP n/j/y) (#27361).
        // Avoid `$year % 100` / digits2 under NestedJIT — `%` has miscompiled to 0 (#27361).
        if ('n/j/y' === $format) {
            $yy = $year - (intdiv($year, 100) * 100);
            if ($yy < 0) {
                $yy = -$yy;
            }
            if ($yy < 10) {
                return (string) $month.'/'.(string) $day.'/0'.(string) $yy;
            }

            return (string) $month.'/'.(string) $day.'/'.(string) $yy;
        }

        return self::formatTokensScalar(
            $format,
            $timestamp,
            $microsecond,
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second
        );
    }

    /**
     * Object-zone T/e/O/P — NestedJIT-safe table (peer DefaultTimezoneCivilJitHelper, #34610).
     *
     * php-src: ext/date/php_date.c — php_format_date tokens T/e/O/P
     */
    private static function formatObjectTimezoneToken(string $token, int $timestamp, string $tzName): string
    {
        if ('e' === $token) {
            if ('Etc/UTC' === $tzName || 'GMT' === $tzName || 'Z' === $tzName) {
                return 'UTC';
            }

            return $tzName;
        }
        if ('T' === $token) {
            return self::abbreviationForZone($tzName, $timestamp);
        }
        $off = self::tableOffsetSeconds($tzName, $timestamp);
        if ('O' === $token) {
            return self::formatOffsetO($off);
        }
        if ('P' === $token) {
            return self::formatOffsetP($off);
        }

        return '';
    }

    private static function abbreviationForZone(string $tzName, int $timestamp): string
    {
        if ('UTC' === $tzName || 'Etc/UTC' === $tzName || 'GMT' === $tzName || 'Z' === $tzName) {
            return 'UTC';
        }
        if ('Europe/Berlin' === $tzName || 'Europe/Paris' === $tzName || 'Europe/Amsterdam' === $tzName) {
            return self::inEuSummer2022to2027($timestamp) ? 'CEST' : 'CET';
        }
        if ('Europe/London' === $tzName) {
            return self::inEuSummer2022to2027($timestamp) ? 'BST' : 'GMT';
        }
        if ('America/New_York' === $tzName) {
            return self::inUsEasternSummer2022to2027($timestamp) ? 'EDT' : 'EST';
        }
        if ('Asia/Tokyo' === $tzName) {
            return 'JST';
        }

        return $tzName;
    }

    private static function tableOffsetSeconds(string $tzName, int $timestamp): int
    {
        if ('UTC' === $tzName || 'Etc/UTC' === $tzName || 'GMT' === $tzName || 'Z' === $tzName) {
            return 0;
        }
        $numeric = self::parseNumericTimezoneOffsetSeconds($tzName);
        if (0 !== $numeric) {
            return $numeric;
        }
        if ('Europe/Berlin' === $tzName || 'Europe/Paris' === $tzName || 'Europe/Amsterdam' === $tzName) {
            return self::inEuSummer2022to2027($timestamp) ? 7200 : 3600;
        }
        if ('Europe/London' === $tzName) {
            return self::inEuSummer2022to2027($timestamp) ? 3600 : 0;
        }
        if ('America/New_York' === $tzName) {
            return self::inUsEasternSummer2022to2027($timestamp) ? -14400 : -18000;
        }
        if ('Asia/Tokyo' === $tzName) {
            return 32400;
        }

        return 0;
    }

    private static function formatOffsetO(int $off): string
    {
        if (0 === $off) {
            return '+0000';
        }
        if (3600 === $off) {
            return '+0100';
        }
        if (7200 === $off) {
            return '+0200';
        }
        if (-14400 === $off) {
            return '-0400';
        }
        if (-18000 === $off) {
            return '-0500';
        }
        if (32400 === $off) {
            return '+0900';
        }

        return '+0000';
    }

    private static function formatOffsetP(int $off): string
    {
        if (0 === $off) {
            return '+00:00';
        }
        if (3600 === $off) {
            return '+01:00';
        }
        if (7200 === $off) {
            return '+02:00';
        }
        if (-14400 === $off) {
            return '-04:00';
        }
        if (-18000 === $off) {
            return '-05:00';
        }
        if (32400 === $off) {
            return '+09:00';
        }

        return '+00:00';
    }

    /** Absolute unix windows — NestedJIT large `%` is unreliable (#33956). */
    private static function inEuSummer2022to2027(int $timestamp): bool
    {
        return ($timestamp >= 1648771200 && $timestamp < 1666872000)
            || ($timestamp >= 1680220800 && $timestamp < 1698321600)
            || ($timestamp >= 1711843200 && $timestamp < 1729994400)
            || ($timestamp >= 1743292800 && $timestamp < 1761444000)
            || ($timestamp >= 1774742400 && $timestamp < 1792893600)
            || ($timestamp >= 1806192000 && $timestamp < 1824343200);
    }

    private static function inUsEasternSummer2022to2027(int $timestamp): bool
    {
        return ($timestamp >= 1647158400 && $timestamp < 1667718000)
            || ($timestamp >= 1678608000 && $timestamp < 1699167600)
            || ($timestamp >= 1710057600 && $timestamp < 1730617200)
            || ($timestamp >= 1741507200 && $timestamp < 1762066800)
            || ($timestamp >= 1772956800 && $timestamp < 1793516400)
            || ($timestamp >= 1804406400 && $timestamp < 1824966000);
    }

    /**
     * Civil yyyymmdd from days since Unix epoch (Howard Hinnant). Single int return for NestedJIT.
     */
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

    private static function formatTokensScalar(
        string $format,
        int $timestamp,
        int $microsecond,
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second
    ): string {
        $out = '';
        $len = \strlen($format);
        $i = 0;
        while ($i < $len) {
            $ch = $format[$i];
            if ('\\' === $ch && $i + 1 < $len) {
                ++$i;
                $out = $out.$format[$i];
                ++$i;

                continue;
            }
            if ('Y' === $ch) {
                $out = $out.self::digits4($year);
            } elseif ('m' === $ch) {
                $out = $out.self::digits2($month);
            } elseif ('d' === $ch) {
                $out = $out.self::digits2($day);
            } elseif ('H' === $ch) {
                $out = $out.self::digits2($hour);
            } elseif ('i' === $ch) {
                $out = $out.self::digits2($minute);
            } elseif ('s' === $ch) {
                $out = $out.self::digits2($second);
            } elseif ('U' === $ch) {
                $out = $out.(string) $timestamp;
            } elseif ('u' === $ch) {
                $out = $out.self::digits6($microsecond);
            } elseif ('n' === $ch) {
                $out = $out.(string) $month;
            } elseif ('j' === $ch) {
                $out = $out.(string) $day;
            } elseif ('y' === $ch) {
                // Prefer intdiv over `%` — NestedJIT `%` miscompile (#27361).
                $y2 = $year - (intdiv($year, 100) * 100);
                if ($y2 < 0) {
                    $y2 = -$y2;
                }
                if ($y2 < 10) {
                    $out = $out.'0'.(string) $y2;
                } else {
                    $out = $out.(string) $y2;
                }
            } elseif ('G' === $ch) {
                $out = $out.(string) $hour;
            } else {
                $out = $out.$ch;
            }
            ++$i;
        }

        return $out;
    }

    private static function digits2(int $value): string
    {
        if ($value < 0) {
            $value = -$value;
        }
        if ($value < 10) {
            return '0'.(string) $value;
        }

        return (string) $value;
    }

    /** php-src date format 'u' — microseconds zero-padded to 6 digits. */
    private static function digits6(int $value): string
    {
        if ($value < 0) {
            $value = -$value;
        }
        if ($value > 999999) {
            $value = $value % 1000000;
        }
        if ($value < 10) {
            return '00000'.(string) $value;
        }
        if ($value < 100) {
            return '0000'.(string) $value;
        }
        if ($value < 1000) {
            return '000'.(string) $value;
        }
        if ($value < 10000) {
            return '00'.(string) $value;
        }
        if ($value < 100000) {
            return '0'.(string) $value;
        }

        return (string) $value;
    }

    private static function digits4(int $value): string
    {
        if ($value < 0) {
            return '-'.self::digits4(-$value);
        }
        if ($value < 10) {
            return '000'.(string) $value;
        }
        if ($value < 100) {
            return '00'.(string) $value;
        }
        if ($value < 1000) {
            return '0'.(string) $value;
        }

        return (string) $value;
    }

    /**
     * Fixed ±HHMM / ±HH:MM offsets only.
     */
    private static function parseNumericTimezoneOffsetSeconds(string $tzName): int
    {
        $len = \strlen($tzName);
        if (5 !== $len && 6 !== $len) {
            return 0;
        }
        $signCh = $tzName[0];
        if ('+' !== $signCh && '-' !== $signCh) {
            return 0;
        }
        if (5 === $len) {
            $hours = (int) substr($tzName, 1, 2);
            $minutes = (int) substr($tzName, 3, 2);
        } elseif (':' === $tzName[3]) {
            $hours = (int) substr($tzName, 1, 2);
            $minutes = (int) substr($tzName, 4, 2);
        } else {
            return 0;
        }
        $seconds = $hours * 3600 + $minutes * 60;

        return '-' === $signCh ? -$seconds : $seconds;
    }

    /**
     * IANA / named zone offset via host date() when available (#27142).
     * Returns 0 for UTC aliases or when date()/timezone APIs are unavailable (NestedJIT).
     */
    private static function namedTimezoneOffsetSeconds(string $tzName, int $timestamp): int
    {
        if ('' === $tzName || 'UTC' === $tzName || 'GMT' === $tzName || 'Z' === $tzName) {
            return 0;
        }
        if (!\function_exists('date') || !\function_exists('date_default_timezone_set')
            || !\function_exists('date_default_timezone_get')) {
            return 0;
        }
        $previous = \date_default_timezone_get();
        if (!@\date_default_timezone_set($tzName)) {
            return 0;
        }
        $offset = (int) \date('Z', $timestamp);
        \date_default_timezone_set($previous);

        return $offset;
    }
}

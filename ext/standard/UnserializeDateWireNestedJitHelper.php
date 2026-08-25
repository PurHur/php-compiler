<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin-AOT NestedJIT parse of Zend date extension serialize wires (#34599 / peer #34594).
 *
 * Int-only outs (NestedJIT dynamic string builds / string-typed helper params for needles
 * segfault under thin AOT). Timezone bytes are sliced from the payload on the LLVM call site.
 *
 * php-src: ext/date/php_date.c — php_date_unserialize / Date*::__unserialize
 */
final class UnserializeDateWireNestedJitHelper
{
    private static int $outTimestamp = 0;

    private static int $outMicrosecond = 0;

    private static int $outTzOff = 0;

    private static int $outTzLen = 0;

    private static int $outY = 0;

    private static int $outM = 0;

    private static int $outD = 0;

    private static int $outH = 0;

    private static int $outI = 0;

    private static int $outS = 0;

    private static float $outF = 0.0;

    private static int $outInvert = 0;

    private static int $outDays = 0;

    private static int $outDaysIsFalse = 1;

    /** @return int 1 on success */
    public static function parseDateTimeLike(string $payload): int
    {
        $len = \strlen($payload);
        $tzOff = -1;
        $tzLen = 0;
        $pos = 0;
        while ($pos + 18 < $len) {
            if ('s' === $payload[$pos] && ':' === $payload[$pos + 1]
                && '8' === $payload[$pos + 2] && ':' === $payload[$pos + 3]
                && '"' === $payload[$pos + 4]
                && 't' === $payload[$pos + 5] && 'i' === $payload[$pos + 6]
                && 'm' === $payload[$pos + 7] && 'e' === $payload[$pos + 8]
                && 'z' === $payload[$pos + 9] && 'o' === $payload[$pos + 10]
                && 'n' === $payload[$pos + 11] && 'e' === $payload[$pos + 12]
                && '"' === $payload[$pos + 13] && ';' === $payload[$pos + 14]
                && 's' === $payload[$pos + 15] && ':' === $payload[$pos + 16]) {
                $p = $pos + 17;
                $n = 0;
                while ($p < $len && $payload[$p] >= '0' && $payload[$p] <= '9') {
                    $n = $n * 10 + (\ord($payload[$p]) - 48);
                    ++$p;
                }
                if ($p + 1 < $len && ':' === $payload[$p] && '"' === $payload[$p + 1]) {
                    $tzOff = $p + 2;
                    $tzLen = $n;
                    break;
                }
            }
            ++$pos;
        }
        if ($tzOff < 0 || $tzLen < 1) {
            return 0;
        }
        $dateOff = -1;
        $dateLen = 0;
        $pos = 0;
        while ($pos + 12 < $len) {
            if ('s' === $payload[$pos] && ':' === $payload[$pos + 1]
                && '4' === $payload[$pos + 2] && ':' === $payload[$pos + 3]
                && '"' === $payload[$pos + 4]
                && 'd' === $payload[$pos + 5] && 'a' === $payload[$pos + 6]
                && 't' === $payload[$pos + 7] && 'e' === $payload[$pos + 8]
                && '"' === $payload[$pos + 9] && ';' === $payload[$pos + 10]
                && 's' === $payload[$pos + 11] && ':' === $payload[$pos + 12]) {
                $p = $pos + 13;
                $n = 0;
                while ($p < $len && $payload[$p] >= '0' && $payload[$p] <= '9') {
                    $n = $n * 10 + (\ord($payload[$p]) - 48);
                    ++$p;
                }
                if ($p + 1 < $len && ':' === $payload[$p] && '"' === $payload[$p + 1]) {
                    $dateOff = $p + 2;
                    $dateLen = $n;
                    break;
                }
            }
            ++$pos;
        }
        if ($dateOff < 0 || $dateLen < 19) {
            return 0;
        }
        if ('-' !== $payload[$dateOff + 4] || '-' !== $payload[$dateOff + 7]
            || ' ' !== $payload[$dateOff + 10] || ':' !== $payload[$dateOff + 13]
            || ':' !== $payload[$dateOff + 16]) {
            return 0;
        }
        $y = self::digitsAt($payload, $dateOff, 4);
        $m = self::digitsAt($payload, $dateOff + 5, 2);
        $d = self::digitsAt($payload, $dateOff + 8, 2);
        $h = self::digitsAt($payload, $dateOff + 11, 2);
        $i = self::digitsAt($payload, $dateOff + 14, 2);
        $s = self::digitsAt($payload, $dateOff + 17, 2);
        $micro = 0;
        if ($dateLen >= 26 && '.' === $payload[$dateOff + 19]) {
            $micro = self::digitsAt($payload, $dateOff + 20, 6);
        }
        if ($y < 1 || $m < 1 || $m > 12 || $d < 1 || $d > 31) {
            return 0;
        }
        // Civil → unix is done on the LLVM side (NestedJIT int-div miscompiles days_from_civil).
        self::$outY = $y;
        self::$outM = $m;
        self::$outD = $d;
        self::$outH = $h;
        self::$outI = $i;
        self::$outS = $s;
        self::$outMicrosecond = $micro;
        self::$outTzOff = $tzOff;
        self::$outTzLen = $tzLen;
        self::$outTimestamp = self::timezoneOffsetSecondsAt($payload, $tzOff, $tzLen);

        return 1;
    }

    /** @return int 1 on success */
    public static function parseDateTimeZone(string $payload): int
    {
        $len = \strlen($payload);
        $pos = 0;
        while ($pos + 18 < $len) {
            if ('s' === $payload[$pos] && ':' === $payload[$pos + 1]
                && '8' === $payload[$pos + 2] && ':' === $payload[$pos + 3]
                && '"' === $payload[$pos + 4]
                && 't' === $payload[$pos + 5] && 'i' === $payload[$pos + 6]
                && 'm' === $payload[$pos + 7] && 'e' === $payload[$pos + 8]
                && 'z' === $payload[$pos + 9] && 'o' === $payload[$pos + 10]
                && 'n' === $payload[$pos + 11] && 'e' === $payload[$pos + 12]
                && '"' === $payload[$pos + 13] && ';' === $payload[$pos + 14]
                && 's' === $payload[$pos + 15] && ':' === $payload[$pos + 16]) {
                $p = $pos + 17;
                $n = 0;
                while ($p < $len && $payload[$p] >= '0' && $payload[$p] <= '9') {
                    $n = $n * 10 + (\ord($payload[$p]) - 48);
                    ++$p;
                }
                if ($p + 1 < $len && ':' === $payload[$p] && '"' === $payload[$p + 1] && $n >= 1) {
                    self::$outTzOff = $p + 2;
                    self::$outTzLen = $n;

                    return 1;
                }
            }
            ++$pos;
        }

        return 0;
    }

    public static function outTimestamp(): int
    {
        return self::$outTimestamp;
    }

    public static function outMicrosecond(): int
    {
        return self::$outMicrosecond;
    }

    public static function outTzOff(): int
    {
        return self::$outTzOff;
    }

    public static function outTzLen(): int
    {
        return self::$outTzLen;
    }

    public static function outY(): int
    {
        return self::$outY;
    }

    public static function outM(): int
    {
        return self::$outM;
    }

    public static function outD(): int
    {
        return self::$outD;
    }

    public static function outH(): int
    {
        return self::$outH;
    }

    public static function outI(): int
    {
        return self::$outI;
    }

    public static function outS(): int
    {
        return self::$outS;
    }

    public static function outF(): float
    {
        return self::$outF;
    }

    public static function outInvert(): int
    {
        return self::$outInvert;
    }

    public static function outDays(): int
    {
        return self::$outDays;
    }

    public static function outDaysIsFalse(): int
    {
        return self::$outDaysIsFalse;
    }

    /** s:1:"X";i:N; — $letterOrd is ord('y') etc (NestedJIT string params are unsafe). */
    private static function findS1Int(string $payload, int $len, int $letterOrd): int
    {
        $pos = 0;
        while ($pos + 9 < $len) {
            if ('s' === $payload[$pos] && ':' === $payload[$pos + 1]
                && '1' === $payload[$pos + 2] && ':' === $payload[$pos + 3]
                && '"' === $payload[$pos + 4]
                && \ord($payload[$pos + 5]) === $letterOrd
                && '"' === $payload[$pos + 6] && ';' === $payload[$pos + 7]
                && 'i' === $payload[$pos + 8] && ':' === $payload[$pos + 9]) {
                return self::readIntAt($payload, $len, $pos + 10);
            }
            ++$pos;
        }

        return 0;
    }

    private static function findS1FloatF(string $payload, int $len): float
    {
        $pos = 0;
        while ($pos + 9 < $len) {
            if ('s' === $payload[$pos] && ':' === $payload[$pos + 1]
                && '1' === $payload[$pos + 2] && ':' === $payload[$pos + 3]
                && '"' === $payload[$pos + 4]
                && 'f' === $payload[$pos + 5]
                && '"' === $payload[$pos + 6] && ';' === $payload[$pos + 7]
                && 'd' === $payload[$pos + 8] && ':' === $payload[$pos + 9]) {
                return self::readFloatAt($payload, $len, $pos + 10);
            }
            ++$pos;
        }

        return 0.0;
    }

    private static function findInvert(string $payload, int $len): int
    {
        // s:6:"invert";i:
        $pos = 0;
        while ($pos + 16 < $len) {
            if ('s' === $payload[$pos] && ':' === $payload[$pos + 1]
                && '6' === $payload[$pos + 2] && ':' === $payload[$pos + 3]
                && '"' === $payload[$pos + 4]
                && 'i' === $payload[$pos + 5] && 'n' === $payload[$pos + 6]
                && 'v' === $payload[$pos + 7] && 'e' === $payload[$pos + 8]
                && 'r' === $payload[$pos + 9] && 't' === $payload[$pos + 10]
                && '"' === $payload[$pos + 11] && ';' === $payload[$pos + 12]
                && 'i' === $payload[$pos + 13] && ':' === $payload[$pos + 14]) {
                return self::readIntAt($payload, $len, $pos + 15);
            }
            ++$pos;
        }

        return 0;
    }

    /** @return int -1 absent, 0/1 bool */
    private static function findDaysBool(string $payload, int $len): int
    {
        $pos = 0;
        while ($pos + 14 < $len) {
            if ('s' === $payload[$pos] && ':' === $payload[$pos + 1]
                && '4' === $payload[$pos + 2] && ':' === $payload[$pos + 3]
                && '"' === $payload[$pos + 4]
                && 'd' === $payload[$pos + 5] && 'a' === $payload[$pos + 6]
                && 'y' === $payload[$pos + 7] && 's' === $payload[$pos + 8]
                && '"' === $payload[$pos + 9] && ';' === $payload[$pos + 10]
                && 'b' === $payload[$pos + 11] && ':' === $payload[$pos + 12]) {
                return '1' === $payload[$pos + 13] ? 1 : 0;
            }
            ++$pos;
        }

        return -1;
    }

    private static function findDaysIntAfter(string $payload, int $len): int
    {
        $pos = 0;
        while ($pos + 14 < $len) {
            if ('s' === $payload[$pos] && ':' === $payload[$pos + 1]
                && '4' === $payload[$pos + 2] && ':' === $payload[$pos + 3]
                && '"' === $payload[$pos + 4]
                && 'd' === $payload[$pos + 5] && 'a' === $payload[$pos + 6]
                && 'y' === $payload[$pos + 7] && 's' === $payload[$pos + 8]
                && '"' === $payload[$pos + 9] && ';' === $payload[$pos + 10]
                && 'i' === $payload[$pos + 11] && ':' === $payload[$pos + 12]) {
                return $pos + 13;
            }
            ++$pos;
        }

        return -1;
    }

    private static function readIntAt(string $payload, int $len, int $pos): int
    {
        $neg = false;
        if ($pos < $len && '-' === $payload[$pos]) {
            $neg = true;
            ++$pos;
        }
        $num = 0;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $num = $num * 10 + (\ord($payload[$pos]) - 48);
            ++$pos;
        }

        return $neg ? 0 - $num : $num;
    }

    private static function readFloatAt(string $payload, int $len, int $pos): float
    {
        $neg = false;
        if ($pos < $len && '-' === $payload[$pos]) {
            $neg = true;
            ++$pos;
        }
        $intPart = 0;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $intPart = $intPart * 10 + (\ord($payload[$pos]) - 48);
            ++$pos;
        }
        $frac = 0.0;
        $scale = 0.1;
        if ($pos < $len && '.' === $payload[$pos]) {
            ++$pos;
            while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                $frac += (\ord($payload[$pos]) - 48) * $scale;
                $scale *= 0.1;
                ++$pos;
            }
        }
        $v = $intPart + $frac;

        return $neg ? 0.0 - $v : $v;
    }

    private static function digitsAt(string $s, int $off, int $n): int
    {
        $num = 0;
        $i = 0;
        while ($i < $n) {
            $ch = $s[$off + $i];
            if ($ch < '0' || $ch > '9') {
                return 0;
            }
            $num = $num * 10 + (\ord($ch) - 48);
            ++$i;
        }

        return $num;
    }

    private static function timezoneOffsetSecondsAt(string $payload, int $off, int $n): int
    {
        if (3 === $n && 'U' === $payload[$off] && 'T' === $payload[$off + 1] && 'C' === $payload[$off + 2]) {
            return 0;
        }
        if (3 === $n && 'G' === $payload[$off] && 'M' === $payload[$off + 1] && 'T' === $payload[$off + 2]) {
            return 0;
        }
        if (1 === $n && 'Z' === $payload[$off]) {
            return 0;
        }
        if ($n < 1) {
            return 0;
        }
        $signCh = $payload[$off];
        if ('+' !== $signCh && '-' !== $signCh) {
            return 0;
        }
        $sign = '+' === $signCh ? 1 : -1;
        $hh = 0;
        $mm = 0;
        if ($n >= 6 && ':' === $payload[$off + 3]) {
            $hh = self::digitsAt($payload, $off + 1, 2);
            $mm = self::digitsAt($payload, $off + 4, 2);
        } elseif ($n >= 5) {
            $hh = self::digitsAt($payload, $off + 1, 2);
            $mm = self::digitsAt($payload, $off + 3, 2);
        } elseif ($n >= 3) {
            $hh = self::digitsAt($payload, $off + 1, 2);
        }

        return $sign * ($hh * 3600 + $mm * 60);
    }
}

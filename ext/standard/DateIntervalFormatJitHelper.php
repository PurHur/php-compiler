<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * date_interval_format() format walk for compiled JIT/AOT modules (#9499, php-in-PHP).
 *
 * Int-only NestedJIT body — float params / VmDateInterval::format float ops SIGSEGV
 * under thin AOT (#34602 residual / peer #34599).
 *
 * SSOT for non-NestedJIT: {@see VmDateInterval::format}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_interval_format)
 */
final class DateIntervalFormatJitHelper
{
    public static function formatFromScalars(
        int $y,
        int $m,
        int $d,
        int $h,
        int $i,
        int $s,
        int $fMicros,
        int $invert,
        int $daysIsInt,
        int $daysInt,
        string $format
    ): string {
        $out = '';
        $len = \strlen($format);
        $pos = 0;
        while ($pos < $len) {
            $ch = $format[$pos];
            if ('%' !== $ch) {
                $out .= $ch;
                ++$pos;

                continue;
            }
            if ($pos + 1 >= $len) {
                $out .= '%';
                ++$pos;

                continue;
            }
            $code = $format[$pos + 1];
            $pos += 2;
            if ('y' === $code) {
                $out .= (string) $y;
            } elseif ('Y' === $code) {
                $out .= self::pad2($y);
            } elseif ('m' === $code) {
                $out .= (string) $m;
            } elseif ('M' === $code) {
                $out .= self::pad2($m);
            } elseif ('d' === $code) {
                $out .= (string) $d;
            } elseif ('D' === $code) {
                $out .= self::pad2($d);
            } elseif ('h' === $code) {
                $out .= (string) $h;
            } elseif ('H' === $code) {
                $out .= self::pad2($h);
            } elseif ('i' === $code) {
                $out .= (string) $i;
            } elseif ('I' === $code) {
                $out .= self::pad2($i);
            } elseif ('s' === $code) {
                $out .= (string) $s;
            } elseif ('S' === $code) {
                $out .= self::pad2($s);
            } elseif ('f' === $code) {
                $out .= (string) $fMicros;
            } elseif ('a' === $code) {
                $out .= 0 !== $daysIsInt ? (string) $daysInt : '(unknown)';
            } elseif ('R' === $code) {
                $out .= 0 !== $invert ? '-' : '+';
            } elseif ('r' === $code) {
                if (0 !== $invert) {
                    $out .= '-';
                }
            } elseif ('%' === $code) {
                $out .= '%';
            } else {
                $out .= '%'.$code;
            }
        }

        return $out;
    }

    private static function pad2(int $n): string
    {
        if ($n < 0) {
            return (string) $n;
        }
        if ($n < 10) {
            return '0'.(string) $n;
        }

        return (string) $n;
    }
}

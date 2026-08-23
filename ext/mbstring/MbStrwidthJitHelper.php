<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * Lowered into JIT/AOT modules that call mb_strwidth() / mb_strimwidth() / mb_str_pad() at runtime
 * (#3495, #34264, php-in-PHP).
 *
 * NestedJIT must not call {@see VmMbstring::strimwidth} / {@see strlen} here when start/width are
 * runtime ints — strlen silent-returns 0 and VmMbstring SIGSEGVs under thin AOT (#34264). Peel uses
 * isset-index length + substr (peer {@see MbSearchJitHelper} / MbSubstrCountJitHelper).
 *
 * SSOT (VM / compile-time fold): {@see VmMbstring::strwidth()} / strimwidth / strPad
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strwidth), mb_strimwidth, mb_str_pad
 */
final class MbStrwidthJitHelper
{
    public static function strwidth(string $string, string $encoding): int
    {
        return VmMbstring::strwidth($string, $encoding);
    }

    public static function strimwidth(
        string $string,
        int $from,
        int $width,
        string $trimmarker,
        string $encoding
    ): string {
        // Character-oriented peel for UTF-8/ASCII/8BIT. Display width ≈ 1 per codepoint for the
        // latin/ü repro; wide East-Asian stays accurate on VM / literal-fold via VmMbstring.
        unset($encoding);
        $charLen = self::utf8CharLength($string);
        if (0 !== $from) {
            if ($from < 0) {
                $from += $charLen;
            }
            if ($from < 0 || $from > $charLen) {
                return '';
            }
            $string = self::utf8Substr($string, $from, $charLen - $from);
            $charLen = $charLen - $from;
        }
        $markerLen = self::utf8CharLength($trimmarker);
        if ($width < 0) {
            $width = $charLen + $width;
            if ($width < 0) {
                return '';
            }
        }
        if ($charLen <= $width) {
            return $string;
        }
        if ('' !== $trimmarker && $width <= $markerLen) {
            return $trimmarker;
        }
        $content = $width - $markerLen;
        if ($content <= 0) {
            return $trimmarker;
        }
        if ($content >= $charLen) {
            return $string.$trimmarker;
        }

        return self::utf8Substr($string, 0, $content).$trimmarker;
    }

    public static function strPad(
        string $input,
        int $padLength,
        string $padString,
        int $padType,
        string $encoding
    ): string {
        return VmMbstring::strPad($input, $padLength, $padString, $padType, $encoding);
    }

    /** NestedJIT-safe length: strlen silent-0 here (#34264). */
    private static function byteLength(string $string): int
    {
        $n = 0;
        while (isset($string[$n])) {
            ++$n;
            if ($n > 1048576) {
                break;
            }
        }

        return $n;
    }

    private static function utf8CharLength(string $string): int
    {
        $n = 0;
        $i = 0;
        $len = self::byteLength($string);
        while ($i < $len) {
            $b = \ord($string[$i]);
            if ($b < 0x80) {
                $step = 1;
            } elseif ($b < 0xE0) {
                $step = 2;
            } elseif ($b < 0xF0) {
                $step = 3;
            } else {
                $step = 4;
            }
            $i += $step;
            ++$n;
            if ($n > $len) {
                break;
            }
        }

        return $n;
    }

    private static function utf8Substr(string $string, int $charFrom, int $charCount): string
    {
        if ($charCount <= 0) {
            return '';
        }
        $i = 0;
        $len = self::byteLength($string);
        $seen = 0;
        while ($i < $len && $seen < $charFrom) {
            $b = \ord($string[$i]);
            if ($b < 0x80) {
                $step = 1;
            } elseif ($b < 0xE0) {
                $step = 2;
            } elseif ($b < 0xF0) {
                $step = 3;
            } else {
                $step = 4;
            }
            $i += $step;
            ++$seen;
        }
        if ($i >= $len) {
            return '';
        }
        $start = $i;
        $taken = 0;
        while ($i < $len && $taken < $charCount) {
            $b = \ord($string[$i]);
            if ($b < 0x80) {
                $step = 1;
            } elseif ($b < 0xE0) {
                $step = 2;
            } elseif ($b < 0xF0) {
                $step = 3;
            } else {
                $step = 4;
            }
            $i += $step;
            ++$taken;
        }

        return \substr($string, $start, $i - $start);
    }
}

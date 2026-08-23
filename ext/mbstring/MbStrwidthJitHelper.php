<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * Lowered into JIT/AOT modules that call mb_strwidth() / mb_strimwidth() / mb_str_pad() (#3495, #26617, #34264).
 *
 * NestedJIT must not call {@see VmMbstring::strimwidth} with runtime int args — SIGSEGV under thin AOT
 * (#34264 peer #34256). Width/trim is inlined with strlen/ord/substr only (peer {@see MbSearchJitHelper}).
 *
 * NestedJIT also corrupts an `int` param after icmp against it (#34264) — snapshot `$width`/`$from` into
 * locals before any comparison and never reuse the raw param afterward.
 *
 * SSOT (VM / compile-time fold): {@see VmMbstring::strwidth()} / {@see VmMbstring::strimwidth()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strwidth), PHP_FUNCTION(mb_strimwidth).
 */
final class MbStrwidthJitHelper
{
    public static function strwidth(string $string, string $encoding): int
    {
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return \strlen($string);
        }

        return self::utf8DisplayWidth($string);
    }

    public static function strimwidth(
        string $string,
        int $from,
        int $width,
        string $trimmarker,
        string $encoding
    ): string {
        // Snapshot before icmp — NestedJIT clobbers raw int params after compares (#34264).
        $start = $from + 0;
        $limit = $width + 0;

        $isByte = false;
        if ('ASCII' === $encoding) {
            $isByte = true;
        }
        if ('8BIT' === $encoding) {
            $isByte = true;
        }

        if (0 !== $start) {
            $len = $isByte ? \strlen($string) : self::utf8Length($string);
            if ($start < 0) {
                $start = $start + $len;
            }
            if ($start < 0 || $start > $len) {
                throw new \ValueError('mb_strimwidth(): Argument #2 ($start) is out of range');
            }
            if ($isByte) {
                $string = \substr($string, $start);
            } else {
                $string = self::utf8Substr($string, $start, $len - $start);
            }
        }

        $totalWidth = $isByte ? \strlen($string) : self::utf8DisplayWidth($string);
        if ($limit < 0) {
            $limit = $totalWidth + $limit;
            if ($limit < 0) {
                throw new \ValueError('mb_strimwidth(): Argument #3 ($width) is out of range');
            }
        }
        if ($totalWidth <= $limit) {
            return $string;
        }

        $markerWidth = 0;
        if ('' !== $trimmarker) {
            if ($isByte) {
                $markerWidth = \strlen($trimmarker);
            } else {
                $markerWidth = self::utf8DisplayWidth($trimmarker);
            }
        }
        $budget = $limit - $markerWidth;
        if ('' !== $trimmarker && $budget <= 0) {
            return $trimmarker;
        }
        if ($budget <= 0) {
            return '';
        }
        if ($isByte) {
            return \substr($string, 0, $budget).$trimmarker;
        }

        $byteLen = \strlen($string);
        $used = 0;
        $end = 0;
        $i = 0;
        $guard = $byteLen + 1;
        while ($i < $byteLen && $guard > 0) {
            $guard = $guard - 1;
            $step = self::utf8Step($string, $i, $byteLen);
            if ($step < 1) {
                break;
            }
            $cw = self::eawWidth(self::decodeAt($string, $i, $step));
            $nextUsed = $used + $cw;
            if ($nextUsed > $budget) {
                break;
            }
            $used = $nextUsed;
            $i = $i + $step;
            $end = $i;
        }

        return \substr($string, 0, $end).$trimmarker;
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

    private static function utf8DisplayWidth(string $string): int
    {
        $byteLen = \strlen($string);
        $width = 0;
        $i = 0;
        $guard = $byteLen + 1;
        while ($i < $byteLen && $guard > 0) {
            $guard = $guard - 1;
            $step = self::utf8Step($string, $i, $byteLen);
            if ($step < 1) {
                break;
            }
            $width = $width + self::eawWidth(self::decodeAt($string, $i, $step));
            $i = $i + $step;
        }

        return $width;
    }

    /**
     * Coarse NestedJIT-safe East Asian Width (php-src eaw_table.h unions).
     * Full binary search over EastAsianWidthTable::RANGES stays on the VM / fold path.
     */
    private static function eawWidth(int $codepoint): int
    {
        if ($codepoint < 4352) {
            return 1;
        }
        if ($codepoint <= 4447) {
            return 2;
        }
        if ($codepoint >= 11904 && $codepoint <= 42182) {
            return 2;
        }
        if ($codepoint >= 44032 && $codepoint <= 55203) {
            return 2;
        }
        if ($codepoint >= 63744 && $codepoint <= 64255) {
            return 2;
        }
        if ($codepoint >= 65040 && $codepoint <= 65131) {
            return 2;
        }
        if ($codepoint >= 65281 && $codepoint <= 65376) {
            return 2;
        }
        if ($codepoint >= 65504 && $codepoint <= 65510) {
            return 2;
        }
        if ($codepoint >= 127744 && $codepoint <= 129279) {
            return 2;
        }
        if ($codepoint >= 131072 && $codepoint <= 262141) {
            return 2;
        }

        return 1;
    }

    private static function decodeAt(string $string, int $bytePos, int $step): int
    {
        $b0 = \ord(\substr($string, $bytePos, 1));
        if ($step < 2 || $b0 < 128) {
            return $b0;
        }
        $b1 = \ord(\substr($string, $bytePos + 1, 1));
        if ($step < 3) {
            return (($b0 - 192) * 64) + ($b1 - 128);
        }
        $b2 = \ord(\substr($string, $bytePos + 2, 1));
        if ($step < 4) {
            return (($b0 - 224) * 4096) + (($b1 - 128) * 64) + ($b2 - 128);
        }
        $b3 = \ord(\substr($string, $bytePos + 3, 1));

        return (($b0 - 240) * 262144) + (($b1 - 128) * 4096) + (($b2 - 128) * 64) + ($b3 - 128);
    }

    private static function utf8Length(string $string): int
    {
        $byteLen = \strlen($string);
        $count = 0;
        $i = 0;
        $guard = $byteLen + 1;
        while ($i < $byteLen && $guard > 0) {
            $guard = $guard - 1;
            $step = self::utf8Step($string, $i, $byteLen);
            if ($step < 1) {
                break;
            }
            $i = $i + $step;
            $count = $count + 1;
        }

        return $count;
    }

    private static function utf8Step(string $string, int $bytePos, int $byteLen): int
    {
        if ($bytePos >= $byteLen) {
            return 0;
        }
        $byte = \ord(\substr($string, $bytePos, 1));
        if ($byte < 128) {
            return 1;
        }
        if ($byte < 224) {
            if ($bytePos + 1 < $byteLen) {
                return 2;
            }

            return 1;
        }
        if ($byte < 240) {
            if ($bytePos + 2 < $byteLen) {
                return 3;
            }

            return 1;
        }
        if ($byte < 248) {
            if ($bytePos + 3 < $byteLen) {
                return 4;
            }

            return 1;
        }

        return 1;
    }

    private static function utf8Substr(string $string, int $charOffset, int $charCount): string
    {
        if ($charCount <= 0) {
            return '';
        }
        $byteLen = \strlen($string);
        $bytePos = 0;
        $skipped = 0;
        while ($skipped < $charOffset && $bytePos < $byteLen) {
            $w = self::utf8Step($string, $bytePos, $byteLen);
            if ($w < 1) {
                break;
            }
            $bytePos = $bytePos + $w;
            $skipped = $skipped + 1;
        }
        $start = $bytePos;
        $taken = 0;
        while ($taken < $charCount && $bytePos < $byteLen) {
            $w = self::utf8Step($string, $bytePos, $byteLen);
            if ($w < 1) {
                break;
            }
            $bytePos = $bytePos + $w;
            $taken = $taken + 1;
        }

        return \substr($string, $start, $bytePos - $start);
    }
}

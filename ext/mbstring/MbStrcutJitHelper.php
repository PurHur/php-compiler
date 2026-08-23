<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helpers for mb_strcut() / mb_substr() (#4573 / #27028 / #34256).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strcut) / PHP_FUNCTION(mb_substr).
 *
 * NestedJIT constraints proven on #34256:
 * - No VmMbstring / VmString; omit length uses call-site sentinel -1 (not int-min).
 * - No private helpers in this unit.
 * - Precompute `$endAt = $start + $length` before the char walk.
 * - Use `$n = $sliceEnd - $sliceStart` then `\substr($string, $sliceStart, $n)`.
 * - Prefer `$found == 0` and nested range ifs (no elseif / ternaries).
 * - Do not branch on `$encoding` before the UTF-8 walk (NestedJIT mis-slice).
 */
final class MbStrcutJitHelper
{
    /** @param int $length negative means cut to end */
    public static function strcutArgv(string $string, int $from, int $length, string $encoding): string
    {
        if ($from < 0) {
            $from = \strlen($string) + $from;
            if ($from < 0) {
                $from = 0;
            }
        }
        if ($length < 0) {
            return \substr($string, $from);
        }

        return \substr($string, $from, $length);
    }
}

final class MbSubstrJitHelper
{
    /**
     * @param int $length -1 means omitted (to end); call site uses -1 sentinel
     */
    public static function substrArgv(
        string $string,
        int $start,
        int $length,
        string $encoding
    ): string {
        $byteLen = \strlen($string);
        $endAt = $start + $length;
        $charIndex = 0;
        $bytePos = 0;
        $sliceStart = $byteLen;
        $sliceEnd = $byteLen;
        $foundStart = 0;
        $foundEnd = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
            if ($foundStart == 0) {
                if ($charIndex == $start) {
                    $sliceStart = $bytePos;
                    $foundStart = 1;
                }
            }
            if ($foundEnd == 0) {
                if ($charIndex == $endAt) {
                    $sliceEnd = $bytePos;
                    $foundEnd = 1;
                }
            }
            $b = \ord(\substr($string, $bytePos, 1));
            $w = 1;
            if ($b >= 192) {
                if ($b < 224) {
                    if ($bytePos + 1 < $byteLen) {
                        $w = 2;
                    }
                }
            }
            if ($b >= 224) {
                if ($b < 240) {
                    if ($bytePos + 2 < $byteLen) {
                        $w = 3;
                    }
                }
            }
            if ($b >= 240) {
                if ($b < 248) {
                    if ($bytePos + 3 < $byteLen) {
                        $w = 4;
                    }
                }
            }
            $bytePos = $bytePos + $w;
            $charIndex = $charIndex + 1;
        }
        if ($foundStart == 0) {
            return '';
        }
        if ($foundEnd == 0) {
            $sliceEnd = $byteLen;
        }
        $n = $sliceEnd - $sliceStart;

        return \substr($string, $sliceStart, $n);
    }
}

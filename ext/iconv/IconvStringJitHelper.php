<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

/**
 * iconv_substr() for compiled JIT/AOT modules (#27197 / #34272, php-in-PHP).
 *
 * NestedJIT constraints (peer #34256 mb_substr):
 * - No {@see VmIconv} / CharsetString under thin AOT (returns false / SIGSEGV).
 * - Omit length uses call-site sentinel -1 (not an extreme int-min token).
 * - No private helpers; precompute `$endAt = $offset + $length` before the char walk.
 * - Do not copy int params into locals before compares.
 *
 * SSOT (VM / compile-time fold): {@see VmIconv::iconvSubstr}
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv_substr)
 */
final class IconvStringJitHelper
{
    /**
     * @param int $length -1 means omitted (to end)
     *
     * @return string|null null → JIT ABI false
     */
    public static function substrArgv(
        string $input,
        int $offset,
        int $length,
        string $encoding
    ): ?string {
        unset($encoding);
        $byteLen = \strlen($input);
        $endAt = $offset + $length;
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
                if ($charIndex == $offset) {
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
            $b = \ord(\substr($input, $bytePos, 1));
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
            return null;
        }
        if ($foundEnd == 0) {
            $sliceEnd = $byteLen;
        }
        $n = $sliceEnd - $sliceStart;

        return \substr($input, $sliceStart, $n);
    }
}

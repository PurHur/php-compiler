<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helper for mb_str_split() (#26870 / #34278).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_str_split)
 *
 * Return type is `array` (not HashTable): NestedJIT maps class HashTable to object ABI
 * (#20652) and SIGSEGVs under thin AOT when length is a runtime int (#34278).
 * {@see JitNestedHelperCoerce::coerceToHashtablePtr} accepts the array/`__value__*` result.
 *
 * Encoding is fixed at the call site (UTF-8/ASCII/8BIT literals only) — 2-arg ABI
 * matches MbSubstrJitHelper / #34256.
 *
 * NestedJIT constraints (peer MbSubstrJitHelper / #34256 / #34270 / #34269):
 * - No VmMbstring / VmString.
 * - No private helpers in this unit.
 * - Subject length via isset-index (strlen silent-0 under NestedJIT).
 * - Compare `($charIndex - $chunkStartChar) == $length` on the param — do not reset a
 *   charsInChunk counter to 0 (that zeros the length param under NestedJIT).
 * - Precompute `$n = $bytePos - $chunkStart` before `\substr`.
 * - Prefer nested range ifs (no elseif / ternaries).
 */
final class MbStrSplitJitHelper
{
    /**
     * @return list<string>
     */
    public static function strSplitRuntimeArgv(string $string, int $length): array
    {
        if ($length <= 0) {
            throw new \ValueError('mb_str_split(): Argument #2 ($length) must be greater than 0');
        }

        $byteLen = 0;
        while (isset($string[$byteLen])) {
            $byteLen = $byteLen + 1;
            if ($byteLen > 1048576) {
                break;
            }
        }

        $parts = [];
        if ($byteLen == 0) {
            return $parts;
        }

        $bytePos = 0;
        $chunkStart = 0;
        $charIndex = 0;
        $chunkStartChar = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
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
            if (($charIndex - $chunkStartChar) == $length) {
                $n = $bytePos - $chunkStart;
                $parts[] = \substr($string, $chunkStart, $n);
                $chunkStart = $bytePos;
                $chunkStartChar = $charIndex;
            }
        }
        if ($chunkStartChar < $charIndex) {
            $n = $bytePos - $chunkStart;
            $parts[] = \substr($string, $chunkStart, $n);
        }

        return $parts;
    }
}

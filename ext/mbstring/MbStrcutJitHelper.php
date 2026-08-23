<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helpers (#34256). Bare \substr works; char walk must stay tiny / branch-light.
 * php-src: ext/mbstring/mbstring.c
 */
final class MbStrcutJitHelper
{
    public static function strcutArgv(string $string, int $from, int $length, string $encoding): string
    {
        // Byte cut; UTF-8 boundary align deferred for NestedJIT size limits.
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
     * Character substr for UTF-8. length === -1 → to end.
     * NestedJIT: avoid large duplicated while bodies — one walk storing byte offsets in locals.
     */
    public static function substrArgv(
        string $string,
        int $start,
        int $length,
        string $encoding
    ): string {
        if (-1 === $length) {
            // to end — compute via byte walk below with huge length
            $length = 2147483647;
        }
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            if ($start < 0) {
                $start = \strlen($string) + $start;
                if ($start < 0) {
                    $start = 0;
                }
            }

            return \substr($string, $start, $length);
        }

        // UTF-8: map character start/end to byte offsets in one forward scan.
        $byteLen = \strlen($string);
        $charIndex = 0;
        $bytePos = 0;
        $sliceStart = -1;
        $sliceEnd = -1;
        $guard = $byteLen + 1;
        while ($bytePos < $byteLen && $guard > 0) {
            $guard = $guard - 1;
            if ($charIndex === $start) {
                $sliceStart = $bytePos;
            }
            $endChar = $start + $length;
            if ($charIndex === $endChar) {
                $sliceEnd = $bytePos;
            }
            $byte = \ord(\substr($string, $bytePos, 1));
            $w = 1;
            if ($byte >= 240 && $byte < 248) {
                if ($bytePos + 3 < $byteLen) {
                    $w = 4;
                }
            } elseif ($byte >= 224 && $byte < 240) {
                if ($bytePos + 2 < $byteLen) {
                    $w = 3;
                }
            } elseif ($byte >= 192 && $byte < 224) {
                if ($bytePos + 1 < $byteLen) {
                    $w = 2;
                }
            }
            $bytePos = $bytePos + $w;
            $charIndex = $charIndex + 1;
        }
        if ($sliceStart < 0) {
            return '';
        }
        if ($sliceEnd < 0) {
            $sliceEnd = $byteLen;
        }

        return \substr($string, $sliceStart, $sliceEnd - $sliceStart);
    }
}

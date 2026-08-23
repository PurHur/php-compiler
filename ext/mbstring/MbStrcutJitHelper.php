<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helpers (#34256). php-src: ext/mbstring/mbstring.c
 *
 * Do not copy int params into locals before compare — NestedJIT has dropped
 * `$wantStart = $start` (treated as 0). Compare `$charIndex == $start` directly.
 */
final class MbStrcutJitHelper
{
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
    public static function substrArgv(
        string $string,
        int $start,
        int $length,
        string $encoding
    ): string {
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            if ($start < 0) {
                $start = \strlen($string) + $start;
                if ($start < 0) {
                    $start = 0;
                }
            }
            if (-1 === $length) {
                return \substr($string, $start);
            }

            return \substr($string, $start, $length);
        }

        $byteLen = \strlen($string);
        $omit = 0;
        if (-1 === $length) {
            $omit = 1;
        }

        $charIndex = 0;
        $bytePos = 0;
        $sliceStart = $byteLen;
        $sliceEnd = $byteLen;
        $foundStart = 0;
        $foundEnd = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
            // Compare params directly — do not copy $start/$length into locals first.
            if (0 === $foundStart) {
                if ($charIndex == $start) {
                    $sliceStart = $bytePos;
                    $foundStart = 1;
                }
            }
            if (0 === $omit) {
                if (0 === $foundEnd) {
                    if ($charIndex == ($start + $length)) {
                        $sliceEnd = $bytePos;
                        $foundEnd = 1;
                    }
                }
            }
            $b = \ord(\substr($string, $bytePos, 1));
            $w = 1;
            if ($b >= 192 && $b < 224) {
                if ($bytePos + 1 < $byteLen) {
                    $w = 2;
                }
            } elseif ($b >= 224 && $b < 240) {
                if ($bytePos + 2 < $byteLen) {
                    $w = 3;
                }
            } elseif ($b >= 240 && $b < 248) {
                if ($bytePos + 3 < $byteLen) {
                    $w = 4;
                }
            }
            $bytePos = $bytePos + $w;
            $charIndex = $charIndex + 1;
        }
        if (0 === $foundStart) {
            return '';
        }
        if (1 === $omit) {
            $sliceEnd = $byteLen;
        } elseif (0 === $foundEnd) {
            $sliceEnd = $byteLen;
        }

        return \substr($string, $sliceStart, $sliceEnd - $sliceStart);
    }
}

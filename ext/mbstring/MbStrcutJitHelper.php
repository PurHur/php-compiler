<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helpers (#34256). php-src: ext/mbstring/mbstring.c
 *
 * Bare \substr with runtime ints works. Avoid negative sentinels / PHP_INT_MIN /
 * hasLength (NestedJIT ABI traps). length==-1 means omitted for substr.
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
        $wantStart = $start;
        if ($wantStart < 0) {
            // Count chars first for negative start.
            $n = 0;
            $p = 0;
            $g = $byteLen + 1;
            while ($p < $byteLen && $g > 0) {
                $g = $g - 1;
                $b = \ord(\substr($string, $p, 1));
                $w = 1;
                if ($b >= 192 && $b < 224) {
                    if ($p + 1 < $byteLen) {
                        $w = 2;
                    }
                } elseif ($b >= 224 && $b < 240) {
                    if ($p + 2 < $byteLen) {
                        $w = 3;
                    }
                } elseif ($b >= 240 && $b < 248) {
                    if ($p + 3 < $byteLen) {
                        $w = 4;
                    }
                }
                $p = $p + $w;
                $n = $n + 1;
            }
            $wantStart = $n + $start;
            if ($wantStart < 0) {
                $wantStart = 0;
            }
        }

        $omit = 0;
        if (-1 === $length) {
            $omit = 1;
        }
        $wantEnd = $wantStart + $length;

        $charIndex = 0;
        $bytePos = 0;
        $sliceStart = $byteLen;
        $sliceEnd = $byteLen;
        $foundStart = 0;
        $foundEnd = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
            if (0 === $foundStart) {
                if ($charIndex == $wantStart) {
                    $sliceStart = $bytePos;
                    $foundStart = 1;
                }
            }
            if (0 === $omit) {
                if (0 === $foundEnd) {
                    if ($charIndex == $wantEnd) {
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

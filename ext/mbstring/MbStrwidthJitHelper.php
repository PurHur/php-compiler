<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helpers for mb_strwidth() / mb_strimwidth() / mb_str_pad() (#3495 / #34262).
 *
 * NestedJIT drops int locals copied from params and mis-handles some arithmetic on params after
 * mutation. Keep a single walk comparing `$charIndex == $from` /
 * `$charIndex == ($from + ($width - \strlen($trimmarker)))` directly — peer MbSubstrJitHelper.
 *
 * NestedJIT display width = 1 per UTF-8 character; negative $from/$width are not peeled here
 * (compile-time fold uses VmMbstring with full EAW + negatives).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strwidth / mb_strimwidth / mb_str_pad).
 */
final class MbStrwidthJitHelper
{
    public static function strwidth(string $string, string $encoding): int
    {
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return \strlen($string);
        }
        $n = 0;
        $byteLen = \strlen($string);
        $bytePos = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
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
            $n = $n + 1;
        }

        return $n;
    }

    public static function strimwidth(
        string $string,
        int $from,
        int $width,
        string $trimmarker,
        string $encoding
    ): string {
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            $byteLen = \strlen($string);
            if ($from > $byteLen) {
                return '';
            }
            if ($byteLen - $from <= $width) {
                return \substr($string, $from);
            }
            if ('' !== $trimmarker && $width <= \strlen($trimmarker)) {
                return $trimmarker;
            }
            if ($width - \strlen($trimmarker) <= 0) {
                return $trimmarker;
            }

            return \substr($string, $from, $width - \strlen($trimmarker)).$trimmarker;
        }

        // UTF-8 NestedJIT peel (1 width per char). Single walk; no param mutation (#34262).
        $byteLen = \strlen($string);
        $charLen = 0;
        $bytePos = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
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
            $charLen = $charLen + 1;
        }

        if ($from > $charLen) {
            return '';
        }

        // Fit: remaining from $from fits in $width.
        if ($charLen <= ($from + $width)) {
            $charIndex = 0;
            $bytePos = 0;
            $sliceStart = 0;
            $foundStart = 0;
            $g = $byteLen + 1;
            while ($bytePos < $byteLen && $g > 0) {
                $g = $g - 1;
                if (0 === $foundStart) {
                    if ($charIndex == $from) {
                        $sliceStart = $bytePos;
                        $foundStart = 1;
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

            return \substr($string, $sliceStart);
        }

        if ('' !== $trimmarker && $width <= \strlen($trimmarker)) {
            return $trimmarker;
        }
        if ($width - \strlen($trimmarker) <= 0) {
            return $trimmarker;
        }

        $charIndex = 0;
        $bytePos = 0;
        $sliceStart = 0;
        $sliceEnd = $byteLen;
        $foundStart = 0;
        $foundEnd = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
            if (0 === $foundStart) {
                if ($charIndex == $from) {
                    $sliceStart = $bytePos;
                    $foundStart = 1;
                }
            }
            if (0 === $foundEnd) {
                if ($charIndex == ($from + ($width - \strlen($trimmarker)))) {
                    $sliceEnd = $bytePos;
                    $foundEnd = 1;
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
        if (0 === $foundEnd) {
            $sliceEnd = $byteLen;
        }

        return \substr($string, $sliceStart, $sliceEnd - $sliceStart).$trimmarker;
    }

    public static function strPad(
        string $input,
        int $padLength,
        string $padString,
        int $padType,
        string $encoding
    ): string {
        // NestedJIT-minimal; compile-time fold uses VmMbstring for correct pad.
        if ($padLength <= \strlen($input)) {
            return $input;
        }
        if ('' === $padString) {
            return $input;
        }

        return $input.$padString;
    }
}

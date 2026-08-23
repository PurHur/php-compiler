<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helpers for mb_strwidth() / mb_strimwidth() / mb_str_pad() (#3495 / #34264 / #34269).
 *
 * NestedJIT zeros int locals copied from params (width-minus-marker temporary) and
 * charLen-minus-from. Compare `$charIndex == $from` /
 * `$charIndex == ($from + ($width - $markerLen))` directly — peer MbSubstrJitHelper / #34256.
 *
 * NestedJIT display width = 1 per UTF-8 character. Compile-time fold uses VmMbstring (full EAW).
 * Subject length via isset-index (strlen silent-0 under NestedJIT, #34264).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strwidth / mb_strimwidth / mb_str_pad).
 */
final class MbStrwidthJitHelper
{
    public static function strwidth(string $string, string $encoding): int
    {
        unset($encoding);
        $n = 0;
        $byteLen = 0;
        while (isset($string[$byteLen])) {
            $byteLen = $byteLen + 1;
            if ($byteLen > 1048576) {
                break;
            }
        }
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
        unset($encoding);

        $byteLen = 0;
        while (isset($string[$byteLen])) {
            $byteLen = $byteLen + 1;
            if ($byteLen > 1048576) {
                break;
            }
        }
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

        $markerLen = 0;
        while (isset($trimmarker[$markerLen])) {
            $markerLen = $markerLen + 1;
            if ($markerLen > 1024) {
                break;
            }
        }

        // Fit: use ($from + $width) — never charLen-minus-from (#34269).
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

        if ('' !== $trimmarker && $width <= $markerLen) {
            return $trimmarker;
        }
        if ($width - $markerLen <= 0) {
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
                // Direct expr — never assign width-minus-marker to a temporary (#34269).
                if ($charIndex == ($from + ($width - $markerLen))) {
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
        unset($padType, $encoding);
        $inputLen = 0;
        while (isset($input[$inputLen])) {
            $inputLen = $inputLen + 1;
            if ($inputLen > 1048576) {
                break;
            }
        }
        if ($padLength <= $inputLen) {
            return $input;
        }
        if ('' === $padString) {
            return $input;
        }

        return $input.$padString;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helpers for mb_strwidth() / mb_strimwidth() / mb_str_pad() (#3495 / #34264 / #34269 / #34270).
 *
 * NestedJIT zeros int locals copied from params (width-minus-marker temporary) and
 * charLen-minus-from. Compare `$charIndex == $from` /
 * `$charIndex == ($from + ($width - $markerLen))` directly — peer MbSubstrJitHelper / #34256.
 *
 * NestedJIT must not call VmMbstring::strPad / strlen when length is a runtime int —
 * strlen silent-returns 0 and VmMbstring SIGSEGVs under thin AOT (#34270). Pad peel uses
 * isset-index length + utf8 substr (peer MbSearchJitHelper / #34264).
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
        // NestedJIT-safe peel — VmMbstring::strPad / strlen misbehave under thin AOT (#34270).
        // Character-oriented for UTF-8/ASCII/8BIT (same as strimwidth peel).
        unset($encoding);
        $inputLength = self::utf8CharLength($input);
        if ($padLength < 0 || $padLength <= $inputLength) {
            return $input;
        }
        if ('' === $padString) {
            return $input;
        }
        $padUnitLength = self::utf8CharLength($padString);
        if (0 === $padUnitLength) {
            return $input;
        }
        if ($padType < 0 || $padType > 2) {
            return $input;
        }
        $numPadUnits = $padLength - $inputLength;
        if (1 === $padType) {
            $leftPad = 0;
            $rightPad = $numPadUnits;
        } elseif (0 === $padType) {
            $leftPad = $numPadUnits;
            $rightPad = 0;
        } else {
            $leftPad = \intdiv($numPadUnits, 2);
            $rightPad = $numPadUnits - $leftPad;
        }

        return self::repeatUtf8Pad($padString, $padUnitLength, $leftPad)
            .$input
            .self::repeatUtf8Pad($padString, $padUnitLength, $rightPad);
    }

    private static function repeatUtf8Pad(string $padString, int $padCharLength, int $charLength): string
    {
        if ($charLength <= 0) {
            return '';
        }
        $fullCopies = \intdiv($charLength, $padCharLength);
        $remainder = $charLength % $padCharLength;
        $result = '';
        $i = 0;
        while ($i < $fullCopies) {
            $result .= $padString;
            ++$i;
        }
        if ($remainder > 0) {
            $result .= self::utf8Substr($padString, 0, $remainder);
        }

        return $result;
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

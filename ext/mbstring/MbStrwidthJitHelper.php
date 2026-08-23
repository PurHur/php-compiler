<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * Lowered into JIT/AOT modules that call mb_strwidth() / mb_strimwidth() at runtime (#3495 / #34264).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strwidth), PHP_FUNCTION(mb_strimwidth).
 *
 * NestedJIT constraints (peer #34256):
 * - No VmMbstring / VmString in strimwidthArgv (silent wrong / SIGSEGV under thin NestedJIT).
 * - No private helpers in this unit; prefer == and nested range ifs.
 * - *Argv name bypasses stale helper-runtime cache; call sites use JitNestedHelperCoerce::callHelper.
 *
 * EastAsianWidthTable is NestedJIT-bundled with this unit (MbStrwidth ensureCompiledBundle).
 */
final class MbStrwidthJitHelper
{
    public static function strwidth(string $string, string $encoding): int
    {
        return VmMbstring::strwidth($string, $encoding);
    }

    /**
     * UTF-8 / ASCII / 8BIT display-width trim (php-src mb_trim_string).
     *
     * @param int $from start char offset (negative = from end)
     * @param int $width display width budget (negative = from total width)
     */
    public static function strimwidthArgv(
        string $string,
        int $from,
        int $width,
        string $trimmarker,
        string $encoding
    ): string {
        $isBytes = 0;
        if ($encoding === 'ASCII') {
            $isBytes = 1;
        }
        if ($encoding === '8BIT') {
            $isBytes = 1;
        }

        if ($from != 0) {
            if ($isBytes == 1) {
                $charLen = \strlen($string);
                if ($from < 0) {
                    $from = $from + $charLen;
                }
                if ($from < 0) {
                    throw new \ValueError('mb_strimwidth(): Argument #2 ($start) is out of range');
                }
                if ($from > $charLen) {
                    throw new \ValueError('mb_strimwidth(): Argument #2 ($start) is out of range');
                }
                $string = \substr($string, $from);
            }
            if ($isBytes == 0) {
                $byteLen = \strlen($string);
                $charLen = 0;
                $bytePos = 0;
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
                    $charLen = $charLen + 1;
                }
                if ($from < 0) {
                    $from = $from + $charLen;
                }
                if ($from < 0) {
                    throw new \ValueError('mb_strimwidth(): Argument #2 ($start) is out of range');
                }
                if ($from > $charLen) {
                    throw new \ValueError('mb_strimwidth(): Argument #2 ($start) is out of range');
                }
                $charIndex = 0;
                $bytePos = 0;
                $sliceStart = $byteLen;
                $foundStart = 0;
                $g = $byteLen + 1;
                while ($bytePos < $byteLen && $g > 0) {
                    $g = $g - 1;
                    if ($foundStart == 0) {
                        if ($charIndex == $from) {
                            $sliceStart = $bytePos;
                            $foundStart = 1;
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
                    $string = '';
                }
                if ($foundStart == 1) {
                    $n = $byteLen - $sliceStart;
                    $string = \substr($string, $sliceStart, $n);
                }
            }
        }

        $totalWidth = self::displayWidthArgv($string, $isBytes);
        if ($width < 0) {
            $width = $totalWidth + $width;
            if ($width < 0) {
                throw new \ValueError('mb_strimwidth(): Argument #3 ($width) is out of range');
            }
        }
        if ($totalWidth <= $width) {
            return $string;
        }

        $markerWidth = 0;
        if ($trimmarker !== '') {
            $markerWidth = self::displayWidthArgv($trimmarker, $isBytes);
        }
        if ($trimmarker !== '') {
            if ($width <= $markerWidth) {
                return $trimmarker;
            }
        }

        $contentWidth = $width - $markerWidth;
        if ($isBytes == 1) {
            if ($contentWidth <= 0) {
                return $trimmarker;
            }
            $byteLen = \strlen($string);
            if ($contentWidth >= $byteLen) {
                return $string.$trimmarker;
            }

            return \substr($string, 0, $contentWidth).$trimmarker;
        }

        return self::trimUtf8ToWidthArgv($string, $contentWidth).$trimmarker;
    }

    public static function displayWidthArgv(string $string, int $isBytes): int
    {
        if ($isBytes == 1) {
            return \strlen($string);
        }
        $byteLen = \strlen($string);
        $width = 0;
        $bytePos = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
            $b0 = \ord(\substr($string, $bytePos, 1));
            $w = 1;
            $cp = $b0;
            if ($b0 >= 192) {
                if ($b0 < 224) {
                    if ($bytePos + 1 < $byteLen) {
                        $w = 2;
                        $b1 = \ord(\substr($string, $bytePos + 1, 1));
                        $cp = (($b0 & 31) << 6) | ($b1 & 63);
                    }
                }
            }
            if ($b0 >= 224) {
                if ($b0 < 240) {
                    if ($bytePos + 2 < $byteLen) {
                        $w = 3;
                        $b1 = \ord(\substr($string, $bytePos + 1, 1));
                        $b2 = \ord(\substr($string, $bytePos + 2, 1));
                        $cp = (($b0 & 15) << 12) | (($b1 & 63) << 6) | ($b2 & 63);
                    }
                }
            }
            if ($b0 >= 240) {
                if ($b0 < 248) {
                    if ($bytePos + 3 < $byteLen) {
                        $w = 4;
                        $b1 = \ord(\substr($string, $bytePos + 1, 1));
                        $b2 = \ord(\substr($string, $bytePos + 2, 1));
                        $b3 = \ord(\substr($string, $bytePos + 3, 1));
                        $cp = (($b0 & 7) << 18) | (($b1 & 63) << 12) | (($b2 & 63) << 6) | ($b3 & 63);
                    }
                }
            }
            $width = $width + EastAsianWidthTable::characterWidth($cp);
            $bytePos = $bytePos + $w;
        }

        return $width;
    }

    public static function trimUtf8ToWidthArgv(string $string, int $contentWidth): string
    {
        if ($contentWidth <= 0) {
            return '';
        }
        $byteLen = \strlen($string);
        $used = 0;
        $outEnd = 0;
        $bytePos = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
            $b0 = \ord(\substr($string, $bytePos, 1));
            $w = 1;
            $cp = $b0;
            if ($b0 >= 192) {
                if ($b0 < 224) {
                    if ($bytePos + 1 < $byteLen) {
                        $w = 2;
                        $b1 = \ord(\substr($string, $bytePos + 1, 1));
                        $cp = (($b0 & 31) << 6) | ($b1 & 63);
                    }
                }
            }
            if ($b0 >= 224) {
                if ($b0 < 240) {
                    if ($bytePos + 2 < $byteLen) {
                        $w = 3;
                        $b1 = \ord(\substr($string, $bytePos + 1, 1));
                        $b2 = \ord(\substr($string, $bytePos + 2, 1));
                        $cp = (($b0 & 15) << 12) | (($b1 & 63) << 6) | ($b2 & 63);
                    }
                }
            }
            if ($b0 >= 240) {
                if ($b0 < 248) {
                    if ($bytePos + 3 < $byteLen) {
                        $w = 4;
                        $b1 = \ord(\substr($string, $bytePos + 1, 1));
                        $b2 = \ord(\substr($string, $bytePos + 2, 1));
                        $b3 = \ord(\substr($string, $bytePos + 3, 1));
                        $cp = (($b0 & 7) << 18) | (($b1 & 63) << 12) | (($b2 & 63) << 6) | ($b3 & 63);
                    }
                }
            }
            $charWidth = EastAsianWidthTable::characterWidth($cp);
            if ($used + $charWidth > $contentWidth) {
                break;
            }
            $used = $used + $charWidth;
            $bytePos = $bytePos + $w;
            $outEnd = $bytePos;
        }

        return \substr($string, 0, $outEnd);
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
}

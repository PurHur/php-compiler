<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\JIT\Builtin\StringStrpos;

/**
 * iconv_strlen/strpos/substr/strrpos for compiled JIT/AOT modules (#27197 / #34272 / #34277).
 *
 * NestedJIT constraints (peer #34256 mb_substr / #34146 mb_strpos):
 * - No {@see VmIconv} / CharsetString under thin AOT (returns false / SIGSEGV).
 * - UTF-8 peel only (encoding arg ignored at runtime; fold path still uses VmIconv).
 * - Each argv is self-contained (no cross-calls between helpers).
 * - Search miss → {@see StringStrpos::NOT_FOUND} (-1) so callers box int|false.
 *
 * SSOT (VM / compile-time fold): {@see VmIconv}
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv_strlen), iconv_strpos, iconv_substr, iconv_strrpos
 */
final class IconvStringJitHelper
{
    /**
     * iconv_strlen() — UTF-8 character count (#34277).
     */
    public static function strlenArgv(string $input, string $encoding): int
    {
        unset($encoding);
        $byteLen = \strlen($input);
        $charIndex = 0;
        $bytePos = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
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

        return $charIndex;
    }

    /**
     * iconv_strpos() — first char offset, or {@see StringStrpos::NOT_FOUND} (#34277).
     *
     * Empty needle → miss (php-src iconv_strpos). Offset ValueError matches CharsetString.
     */
    public static function strposArgv(
        string $haystack,
        string $needle,
        int $offset,
        string $encoding
    ): int {
        unset($encoding);
        if ('' === $needle) {
            return StringStrpos::NOT_FOUND;
        }
        $hayByteLen = \strlen($haystack);
        $needleByteLen = \strlen($needle);
        // Count haystack chars.
        $hayLen = 0;
        $bytePos = 0;
        $g = $hayByteLen + 1;
        while ($bytePos < $hayByteLen && $g > 0) {
            $g = $g - 1;
            $b = \ord(\substr($haystack, $bytePos, 1));
            $w = 1;
            if ($b >= 192) {
                if ($b < 224) {
                    if ($bytePos + 1 < $hayByteLen) {
                        $w = 2;
                    }
                }
            }
            if ($b >= 224) {
                if ($b < 240) {
                    if ($bytePos + 2 < $hayByteLen) {
                        $w = 3;
                    }
                }
            }
            if ($b >= 240) {
                if ($b < 248) {
                    if ($bytePos + 3 < $hayByteLen) {
                        $w = 4;
                    }
                }
            }
            $bytePos = $bytePos + $w;
            $hayLen = $hayLen + 1;
        }
        // Count needle chars.
        $needleLen = 0;
        $bytePos = 0;
        $g = $needleByteLen + 1;
        while ($bytePos < $needleByteLen && $g > 0) {
            $g = $g - 1;
            $b = \ord(\substr($needle, $bytePos, 1));
            $w = 1;
            if ($b >= 192) {
                if ($b < 224) {
                    if ($bytePos + 1 < $needleByteLen) {
                        $w = 2;
                    }
                }
            }
            if ($b >= 224) {
                if ($b < 240) {
                    if ($bytePos + 2 < $needleByteLen) {
                        $w = 3;
                    }
                }
            }
            if ($b >= 240) {
                if ($b < 248) {
                    if ($bytePos + 3 < $needleByteLen) {
                        $w = 4;
                    }
                }
            }
            $bytePos = $bytePos + $w;
            $needleLen = $needleLen + 1;
        }
        if ($offset < 0) {
            $offset = $offset + $hayLen;
            if ($offset < 0) {
                throw new \ValueError(
                    'iconv_strpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'
                );
            }
        }
        if ($offset > $hayLen) {
            throw new \ValueError(
                'iconv_strpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'
            );
        }
        if (0 === $needleLen) {
            return StringStrpos::NOT_FOUND;
        }
        $limit = $hayLen - $needleLen;
        $pos = $offset;
        while ($pos <= $limit) {
            // Slice haystack[$pos .. $pos+$needleLen) as UTF-8 bytes (inlined substrArgv).
            $endAt = $pos + $needleLen;
            $charIndex = 0;
            $bytePos = 0;
            $sliceStart = $hayByteLen;
            $sliceEnd = $hayByteLen;
            $foundStart = 0;
            $foundEnd = 0;
            $g = $hayByteLen + 1;
            while ($bytePos < $hayByteLen && $g > 0) {
                $g = $g - 1;
                if ($foundStart == 0) {
                    if ($charIndex == $pos) {
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
                $b = \ord(\substr($haystack, $bytePos, 1));
                $w = 1;
                if ($b >= 192) {
                    if ($b < 224) {
                        if ($bytePos + 1 < $hayByteLen) {
                            $w = 2;
                        }
                    }
                }
                if ($b >= 224) {
                    if ($b < 240) {
                        if ($bytePos + 2 < $hayByteLen) {
                            $w = 3;
                        }
                    }
                }
                if ($b >= 240) {
                    if ($b < 248) {
                        if ($bytePos + 3 < $hayByteLen) {
                            $w = 4;
                        }
                    }
                }
                $bytePos = $bytePos + $w;
                $charIndex = $charIndex + 1;
            }
            if ($foundStart == 0) {
                return StringStrpos::NOT_FOUND;
            }
            if ($foundEnd == 0) {
                $sliceEnd = $hayByteLen;
            }
            $n = $sliceEnd - $sliceStart;
            $slice = \substr($haystack, $sliceStart, $n);
            if ($slice === $needle) {
                return $pos;
            }
            $pos = $pos + 1;
        }

        return StringStrpos::NOT_FOUND;
    }

    /**
     * iconv_strrpos() — last char offset, or {@see StringStrpos::NOT_FOUND} (#34277).
     */
    public static function strrposArgv(
        string $haystack,
        string $needle,
        string $encoding
    ): int {
        unset($encoding);
        if ('' === $needle) {
            return StringStrpos::NOT_FOUND;
        }
        $hayByteLen = \strlen($haystack);
        $needleByteLen = \strlen($needle);
        $hayLen = 0;
        $bytePos = 0;
        $g = $hayByteLen + 1;
        while ($bytePos < $hayByteLen && $g > 0) {
            $g = $g - 1;
            $b = \ord(\substr($haystack, $bytePos, 1));
            $w = 1;
            if ($b >= 192) {
                if ($b < 224) {
                    if ($bytePos + 1 < $hayByteLen) {
                        $w = 2;
                    }
                }
            }
            if ($b >= 224) {
                if ($b < 240) {
                    if ($bytePos + 2 < $hayByteLen) {
                        $w = 3;
                    }
                }
            }
            if ($b >= 240) {
                if ($b < 248) {
                    if ($bytePos + 3 < $hayByteLen) {
                        $w = 4;
                    }
                }
            }
            $bytePos = $bytePos + $w;
            $hayLen = $hayLen + 1;
        }
        $needleLen = 0;
        $bytePos = 0;
        $g = $needleByteLen + 1;
        while ($bytePos < $needleByteLen && $g > 0) {
            $g = $g - 1;
            $b = \ord(\substr($needle, $bytePos, 1));
            $w = 1;
            if ($b >= 192) {
                if ($b < 224) {
                    if ($bytePos + 1 < $needleByteLen) {
                        $w = 2;
                    }
                }
            }
            if ($b >= 224) {
                if ($b < 240) {
                    if ($bytePos + 2 < $needleByteLen) {
                        $w = 3;
                    }
                }
            }
            if ($b >= 240) {
                if ($b < 248) {
                    if ($bytePos + 3 < $needleByteLen) {
                        $w = 4;
                    }
                }
            }
            $bytePos = $bytePos + $w;
            $needleLen = $needleLen + 1;
        }
        if (0 === $needleLen) {
            return StringStrpos::NOT_FOUND;
        }
        $limit = $hayLen - $needleLen;
        $pos = $limit;
        while ($pos >= 0) {
            $endAt = $pos + $needleLen;
            $charIndex = 0;
            $bytePos = 0;
            $sliceStart = $hayByteLen;
            $sliceEnd = $hayByteLen;
            $foundStart = 0;
            $foundEnd = 0;
            $g = $hayByteLen + 1;
            while ($bytePos < $hayByteLen && $g > 0) {
                $g = $g - 1;
                if ($foundStart == 0) {
                    if ($charIndex == $pos) {
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
                $b = \ord(\substr($haystack, $bytePos, 1));
                $w = 1;
                if ($b >= 192) {
                    if ($b < 224) {
                        if ($bytePos + 1 < $hayByteLen) {
                            $w = 2;
                        }
                    }
                }
                if ($b >= 224) {
                    if ($b < 240) {
                        if ($bytePos + 2 < $hayByteLen) {
                            $w = 3;
                        }
                    }
                }
                if ($b >= 240) {
                    if ($b < 248) {
                        if ($bytePos + 3 < $hayByteLen) {
                            $w = 4;
                        }
                    }
                }
                $bytePos = $bytePos + $w;
                $charIndex = $charIndex + 1;
            }
            if ($foundStart == 0) {
                return StringStrpos::NOT_FOUND;
            }
            if ($foundEnd == 0) {
                $sliceEnd = $hayByteLen;
            }
            $n = $sliceEnd - $sliceStart;
            $slice = \substr($haystack, $sliceStart, $n);
            if ($slice === $needle) {
                return $pos;
            }
            $pos = $pos - 1;
        }

        return StringStrpos::NOT_FOUND;
    }

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

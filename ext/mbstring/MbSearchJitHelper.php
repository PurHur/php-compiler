<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\StringStrpos;

/**
 * mb_strpos() for compiled JIT/AOT modules (#34146 leftover of #27187, php-in-PHP).
 *
 * Returns {@see StringStrpos::NOT_FOUND} (-1) on miss so callers can box int|false.
 *
 * NestedJIT must not call {@see VmMbstring::strpos} / {@see \PHPCompiler\ext\standard\VmString::utf8CharLength}
 * — those methods silent-return 0 under thin AOT NestedJIT. Search is inlined with strlen/ord/substr
 * only; UTF-8 width uses range compares (NestedJIT bitwise `&` loops hang on multibyte lead bytes).
 *
 * SSOT (VM / compile-time fold): {@see VmMbstring::strpos()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strpos)
 */
final class MbSearchJitHelper
{
    public static function strposArgv(
        string $haystack,
        string $needle,
        int $offset,
        string $encoding
    ): int {
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return self::byteStrpos($haystack, $needle, $offset);
        }

        return self::utf8Strpos($haystack, $needle, $offset);
    }

    /**
     * mb_stripos() — case-insensitive (#34158 leftover of #34146).
     *
     * NestedJIT-safe fold: ASCII A–Z → a–z only (UTF-8 lead bytes are ≥128 so untouched).
     * Full Unicode case maps remain on the VM / compile-time fold path via {@see VmMbstring::stripos}.
     */
    public static function striposArgv(
        string $haystack,
        string $needle,
        int $offset,
        string $encoding
    ): int {
        $haystack = self::asciiLower($haystack);
        $needle = self::asciiLower($needle);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return self::byteStrpos($haystack, $needle, $offset);
        }

        return self::utf8Strpos($haystack, $needle, $offset);
    }

    /**
     * mb_strrpos() — reverse search (#34166 leftover of #34146).
     *
     * SSOT (VM / compile-time fold): {@see VmMbstring::strrpos()}
     * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strrpos)
     */
    public static function strrposArgv(
        string $haystack,
        string $needle,
        int $offset,
        string $encoding
    ): int {
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return self::byteStrrpos($haystack, $needle, $offset);
        }

        return self::utf8Strrpos($haystack, $needle, $offset);
    }

    /** ASCII A–Z → a–z; leaves UTF-8 multibyte sequences unchanged. */
    private static function asciiLower(string $string): string
    {
        $byteLen = \strlen($string);
        $out = '';
        $i = 0;
        while ($i < $byteLen) {
            $ch = \substr($string, $i, 1);
            $byte = \ord($ch);
            if ($byte >= 65 && $byte <= 90) {
                // Avoid chr() under NestedJIT (typed as mixed → TypeError).
                $out = $out.\substr('abcdefghijklmnopqrstuvwxyz', $byte - 65, 1);
            } else {
                $out = $out.$ch;
            }
            $i = $i + 1;
        }

        return $out;
    }

    private static function byteStrpos(string $haystack, string $needle, int $offset): int
    {
        $hayLen = \strlen($haystack);
        $needleLen = \strlen($needle);
        $offset = self::normalizeOffset($hayLen, $offset);
        if (0 === $needleLen) {
            return $offset;
        }
        if ($offset > $hayLen - $needleLen) {
            return StringStrpos::NOT_FOUND;
        }
        $pos = $offset;
        while ($pos <= $hayLen - $needleLen) {
            if (\substr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
            $pos = $pos + 1;
        }

        return StringStrpos::NOT_FOUND;
    }

    private static function utf8Strpos(string $haystack, string $needle, int $offset): int
    {
        $hayLen = self::utf8Length($haystack);
        $needleLen = self::utf8Length($needle);
        $offset = self::normalizeOffset($hayLen, $offset);
        if (0 === $needleLen) {
            return $offset;
        }
        if ($offset > $hayLen - $needleLen) {
            return StringStrpos::NOT_FOUND;
        }
        $pos = $offset;
        while ($pos <= $hayLen - $needleLen) {
            if (self::utf8Substr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
            $pos = $pos + 1;
        }

        return StringStrpos::NOT_FOUND;
    }

    private static function byteStrrpos(string $haystack, string $needle, int $offset): int
    {
        $hayLen = \strlen($haystack);
        $needleLen = \strlen($needle);
        $minStart = 0;
        $maxStart = $hayLen - $needleLen;
        if ($offset < 0) {
            $maxStart = $hayLen + $offset;
            if ($maxStart < 0) {
                throw new \ValueError(
                    'mb_strrpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'
                );
            }
            if (0 === $needleLen) {
                return $maxStart;
            }
            $maxStart = $maxStart - $needleLen;
        } else {
            $minStart = $offset;
        }
        if (0 === $needleLen) {
            return $hayLen;
        }
        if ($minStart > $maxStart) {
            return StringStrpos::NOT_FOUND;
        }
        $pos = $maxStart;
        while ($pos >= $minStart) {
            if (\substr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
            $pos = $pos - 1;
        }

        return StringStrpos::NOT_FOUND;
    }

    private static function utf8Strrpos(string $haystack, string $needle, int $offset): int
    {
        $hayLen = self::utf8Length($haystack);
        $needleLen = self::utf8Length($needle);
        $minStart = 0;
        $maxStart = $hayLen - $needleLen;
        if ($offset < 0) {
            $maxStart = $hayLen + $offset;
            if ($maxStart < 0) {
                throw new \ValueError(
                    'mb_strrpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'
                );
            }
            if (0 === $needleLen) {
                return $maxStart;
            }
            $maxStart = $maxStart - $needleLen;
        } else {
            $minStart = $offset;
        }
        if (0 === $needleLen) {
            return $hayLen;
        }
        if ($minStart > $maxStart) {
            return StringStrpos::NOT_FOUND;
        }
        $pos = $maxStart;
        while ($pos >= $minStart) {
            if (self::utf8Substr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
            $pos = $pos - 1;
        }

        return StringStrpos::NOT_FOUND;
    }

    private static function normalizeOffset(int $hayLen, int $offset): int
    {
        if ($offset < 0) {
            $offset = $offset + $hayLen;
        }
        if ($offset < 0 || $offset > $hayLen) {
            throw new \ValueError(
                'mb_strpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'
            );
        }

        return $offset;
    }

    private static function utf8Length(string $string): int
    {
        $byteLen = \strlen($string);
        $count = 0;
        $i = 0;
        // Cap iterations — NestedJIT must not spin if width miscomputes.
        $guard = $byteLen + 1;
        while ($i < $byteLen && $guard > 0) {
            $guard = $guard - 1;
            $step = self::utf8Step($string, $i, $byteLen);
            if ($step < 1) {
                break;
            }
            $i = $i + $step;
            $count = $count + 1;
        }

        return $count;
    }

    /** UTF-8 sequence width via range compares (avoid NestedJIT-hanging bitwise masks). */
    private static function utf8Step(string $string, int $bytePos, int $byteLen): int
    {
        if ($bytePos >= $byteLen) {
            return 0;
        }
        $byte = \ord(\substr($string, $bytePos, 1));
        if ($byte < 128) {
            return 1;
        }
        if ($byte < 224) {
            return ($bytePos + 1 < $byteLen) ? 2 : 1;
        }
        if ($byte < 240) {
            return ($bytePos + 2 < $byteLen) ? 3 : 1;
        }
        if ($byte < 248) {
            return ($bytePos + 3 < $byteLen) ? 4 : 1;
        }

        return 1;
    }

    private static function utf8Substr(string $string, int $charOffset, int $charCount): string
    {
        if ($charCount <= 0) {
            return '';
        }
        $byteLen = \strlen($string);
        $bytePos = 0;
        $skipped = 0;
        while ($skipped < $charOffset && $bytePos < $byteLen) {
            $w = self::utf8Step($string, $bytePos, $byteLen);
            if ($w < 1) {
                break;
            }
            $bytePos = $bytePos + $w;
            $skipped = $skipped + 1;
        }
        $start = $bytePos;
        $taken = 0;
        while ($taken < $charCount && $bytePos < $byteLen) {
            $w = self::utf8Step($string, $bytePos, $byteLen);
            if ($w < 1) {
                break;
            }
            $bytePos = $bytePos + $w;
            $taken = $taken + 1;
        }

        return \substr($string, $start, $bytePos - $start);
    }
}

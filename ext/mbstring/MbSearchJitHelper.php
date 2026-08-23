<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\StringStrpos;

/**
 * mb_strpos() / mb_stripos() / mb_strrpos() / mb_strripos() / mb_strstr() / mb_stristr() for compiled JIT/AOT
 * modules (#34146 / #34158 / #34166 / #34211 / mb_stristr leftover, php-in-PHP).
 *
 * Offset helpers return {@see StringStrpos::NOT_FOUND} (-1) on miss so callers can box int|false.
 * {@see strstrArgv} / {@see stristrArgv} return string|false (nullish false → NestedJIT null).
 *
 * NestedJIT must not call {@see VmMbstring::strpos} / {@see \PHPCompiler\ext\standard\VmString::utf8CharLength}
 * — those methods silent-return 0 under thin AOT NestedJIT. Search is inlined with strlen/ord/substr
 * only; UTF-8 width uses range compares (NestedJIT bitwise `&` loops hang on multibyte lead bytes).
 *
 * SSOT (VM / compile-time fold): {@see VmMbstring::strpos()} / stripos / strrpos / strripos / strstr / stristr
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strpos), mb_stripos, mb_strrpos, mb_strripos, mb_strstr, mb_stristr
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
     * Offset semantics match {@see VmMbstring::strrpos} / php-src mb_strrpos.
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

    /**
     * mb_strripos() — case-insensitive reverse search (peer of #34158 / #34166).
     *
     * NestedJIT-safe fold: ASCII A–Z → a–z only; offset semantics match {@see VmMbstring::strripos}.
     */
    public static function strriposArgv(
        string $haystack,
        string $needle,
        int $offset,
        string $encoding
    ): int {
        $haystack = self::asciiLower($haystack);
        $needle = self::asciiLower($needle);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return self::byteStrrpos($haystack, $needle, $offset);
        }

        return self::utf8Strrpos($haystack, $needle, $offset);
    }

    /**
     * mb_strstr() — first occurrence → string|false (#34211 leftover of #34172).
     *
     * @return string|false
     */
    public static function strstrArgv(
        string $haystack,
        string $needle,
        bool $beforeNeedle,
        string $encoding
    ) {
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            $pos = self::byteStrpos($haystack, $needle, 0);
            if (StringStrpos::NOT_FOUND === $pos) {
                return false;
            }
            if ($beforeNeedle) {
                return \substr($haystack, 0, $pos);
            }

            return \substr($haystack, $pos);
        }

        $pos = self::utf8Strpos($haystack, $needle, 0);
        if (StringStrpos::NOT_FOUND === $pos) {
            return false;
        }
        if ($beforeNeedle) {
            return self::utf8Substr($haystack, 0, $pos);
        }
        $hayLen = self::utf8Length($haystack);

        return self::utf8Substr($haystack, $pos, $hayLen - $pos);
    }

    /**
     * mb_stristr() — case-insensitive strstr (peer of #34211 / #34158).
     *
     * NestedJIT-safe fold: ASCII A–Z → a–z only; full Unicode case maps remain on VM / compile-time fold.
     *
     * @return string|false
     */
    public static function stristrArgv(
        string $haystack,
        string $needle,
        bool $beforeNeedle,
        string $encoding
    ) {
        $hayLower = self::asciiLower($haystack);
        $needleLower = self::asciiLower($needle);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            $pos = self::byteStrpos($hayLower, $needleLower, 0);
            if (StringStrpos::NOT_FOUND === $pos) {
                return false;
            }
            if ($beforeNeedle) {
                return \substr($haystack, 0, $pos);
            }

            return \substr($haystack, $pos);
        }

        $pos = self::utf8Strpos($hayLower, $needleLower, 0);
        if (StringStrpos::NOT_FOUND === $pos) {
            return false;
        }
        if ($beforeNeedle) {
            return self::utf8Substr($haystack, 0, $pos);
        }
        $hayLen = self::utf8Length($haystack);

        return self::utf8Substr($haystack, $pos, $hayLen - $pos);
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

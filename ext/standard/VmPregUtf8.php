<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * UTF-8 helpers for VmPregPure nested JIT — no VM frame deps (#16075).
 *
 * Subset of {@see VmString} utf8 paths; avoids compiling full VmString.php in AOT preg bundle.
 */
final class VmPregUtf8
{
    public static function byteLength(string $string): int
    {
        return \strlen($string);
    }

    public static function utf8CharLength(string $string): int
    {
        $byteLen = self::byteLength($string);
        $count = 0;
        for ($i = 0; $i < $byteLen; ++$count) {
            $byte = \ord($string[$i]);
            if ($byte < 0x80) {
                $i += 1;
            } elseif (($byte & 0xE0) === 0xC0 && $i + 1 < $byteLen) {
                $i += 2;
            } elseif (($byte & 0xF0) === 0xE0 && $i + 2 < $byteLen) {
                $i += 3;
            } elseif (($byte & 0xF8) === 0xF0 && $i + 3 < $byteLen) {
                $i += 4;
            } else {
                $i += 1;
            }
        }

        return $count;
    }

    public static function isValidUtf8(string $string): bool
    {
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ) {
            if (!self::utf8SequenceValidAt($string, $len, $i, $need)) {
                return false;
            }
            $i += $need + 1;
        }

        return true;
    }

    public static function utf8CharSubstr(string $string, int $charOffset, int $charCount): string
    {
        if ($charCount <= 0) {
            return '';
        }
        $byteLen = self::byteLength($string);
        $bytePos = 0;
        for ($skipped = 0; $skipped < $charOffset && $bytePos < $byteLen; ++$skipped) {
            $bytePos += self::utf8CharByteWidth($string, $bytePos);
        }
        $start = $bytePos;
        for ($taken = 0; $taken < $charCount && $bytePos < $byteLen; ++$taken) {
            $bytePos += self::utf8CharByteWidth($string, $bytePos);
        }

        return \substr($string, $start, $bytePos - $start);
    }

    public static function utf8CharByteWidth(string $string, int $bytePos): int
    {
        $byteLen = self::byteLength($string);
        if ($bytePos >= $byteLen) {
            return 0;
        }
        $byte = \ord($string[$bytePos]);
        if ($byte < 0x80) {
            return 1;
        }
        if (($byte & 0xE0) === 0xC0 && $bytePos + 1 < $byteLen) {
            return 2;
        }
        if (($byte & 0xF0) === 0xE0 && $bytePos + 2 < $byteLen) {
            return 3;
        }
        if (($byte & 0xF8) === 0xF0 && $bytePos + 3 < $byteLen) {
            return 4;
        }

        return 1;
    }

    /**
     * @param-out int $need continuation byte count when lead byte is multi-byte
     */
    private static function utf8SequenceValidAt(string $string, int $len, int $i, ?int &$need = null): bool
    {
        $byte = \ord($string[$i]);
        if ($byte < 0x80) {
            $need = 0;

            return true;
        }
        if (($byte & 0xE0) === 0xC0) {
            $need = 1;
            $min = 0x80;
        } elseif (($byte & 0xF0) === 0xE0) {
            $need = 2;
            $min = 0x800;
        } elseif (($byte & 0xF8) === 0xF0) {
            $need = 3;
            $min = 0x10000;
        } else {
            $need = 0;

            return false;
        }
        if ($i + $need >= $len) {
            return false;
        }
        $cp = $byte & (0xFF >> (2 + $need));
        for ($j = 1; $j <= $need; ++$j) {
            $next = \ord($string[$i + $j]);
            if (($next & 0xC0) !== 0x80) {
                return false;
            }
            $cp = ($cp << 6) | ($next & 0x3F);
        }
        if ($cp < $min || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            return false;
        }

        return true;
    }

    /**
     * Decode one UTF-8 code point at $bytePos.
     *
     * @return array{0: int, 1: int}|null [codepoint, byteWidth] or null at EOF / invalid
     */
    public static function codepointAt(string $string, int $bytePos, int $byteLen): ?array
    {
        if ($bytePos >= $byteLen) {
            return null;
        }
        $width = self::utf8CharByteWidth($string, $bytePos);
        if ($width <= 0 || $bytePos + $width > $byteLen) {
            return null;
        }
        $cp = self::decodeUtf8Codepoint($string, $bytePos, $width);
        if (null === $cp) {
            return null;
        }

        return [$cp, $width];
    }

    /**
     * Encode a Unicode code point as UTF-8 bytes (PCRE2 \\x{…} under PCRE2_UTF, #29024).
     *
     * @return string|null UTF-8 bytes, or null when $cp is outside 0..0x10FFFF
     */
    public static function encodeCodepoint(int $cp): ?string
    {
        if ($cp < 0 || $cp > 0x10FFFF) {
            return null;
        }
        if ($cp <= 0x7F) {
            return \chr($cp);
        }
        if ($cp <= 0x7FF) {
            return \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
        }
        if ($cp <= 0xFFFF) {
            return \chr(0xE0 | ($cp >> 12))
                .\chr(0x80 | (($cp >> 6) & 0x3F))
                .\chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            .\chr(0x80 | (($cp >> 12) & 0x3F))
            .\chr(0x80 | (($cp >> 6) & 0x3F))
            .\chr(0x80 | ($cp & 0x3F));
    }

    private static function decodeUtf8Codepoint(string $string, int $bytePos, int $width): ?int
    {
        $b0 = \ord($string[$bytePos]);
        if (1 === $width) {
            return $b0;
        }
        if (2 === $width) {
            return (($b0 & 0x1F) << 6) | (\ord($string[$bytePos + 1]) & 0x3F);
        }
        if (3 === $width) {
            return (($b0 & 0x0F) << 12)
                | ((\ord($string[$bytePos + 1]) & 0x3F) << 6)
                | (\ord($string[$bytePos + 2]) & 0x3F);
        }
        if (4 === $width) {
            return (($b0 & 0x07) << 18)
                | ((\ord($string[$bytePos + 1]) & 0x3F) << 12)
                | ((\ord($string[$bytePos + 2]) & 0x3F) << 6)
                | (\ord($string[$bytePos + 3]) & 0x3F);
        }

        return null;
    }

    /**
     * PCRE2 UCP / Unicode property test for one code point (#22003).
     *
     * $kind: word|digit|space or general-category name (L, Letter, Lu, Nd, …).
     */
    public static function codepointMatchesProp(int $cp, string $kind, bool $negated): bool
    {
        $ok = self::codepointHasProp($cp, $kind);
        if ($negated) {
            $ok = !$ok;
        }

        return $ok;
    }

    private static function codepointHasProp(int $cp, string $kind): bool
    {
        $k = \strtolower($kind);
        if ('word' === $k) {
            // PCRE2_UCP \w ≈ \p{L} | \p{N} | _
            return 0x5F === $cp || self::isLetterCategory($cp) || self::isNumberCategory($cp);
        }
        if ('digit' === $k) {
            return self::isDecimalDigitCategory($cp);
        }
        if ('space' === $k) {
            return self::isSpaceCategory($cp);
        }
        if ('l' === $k || 'letter' === $k) {
            return self::isLetterCategory($cp);
        }
        if ('n' === $k || 'number' === $k) {
            return self::isNumberCategory($cp);
        }
        if ('z' === $k || 'separator' === $k) {
            return self::isSeparatorCategory($cp);
        }
        if ('p' === $k || 'punctuation' === $k) {
            return self::isPunctuationCategory($cp);
        }
        if ('s' === $k || 'symbol' === $k) {
            return self::isSymbolCategory($cp);
        }
        if ('m' === $k || 'mark' === $k) {
            return self::isMarkCategory($cp);
        }
        if ('c' === $k || 'other' === $k) {
            return self::isOtherCategory($cp);
        }

        $type = self::generalCategory($cp);
        $map = [
            'lu' => 1, 'll' => 2, 'lt' => 3, 'lm' => 4, 'lo' => 5,
            'mn' => 6, 'me' => 7, 'mc' => 8,
            'nd' => 9, 'nl' => 10, 'no' => 11,
            'zs' => 12, 'zl' => 13, 'zp' => 14,
            'cc' => 15, 'cf' => 16, 'co' => 17, 'cs' => 18, 'cn' => 0,
            'pd' => 19, 'ps' => 20, 'pe' => 21, 'pc' => 22, 'po' => 23,
            'sm' => 24, 'sc' => 25, 'sk' => 26, 'so' => 27,
            'pi' => 28, 'pf' => 29,
        ];

        return isset($map[$k]) && $type === $map[$k];
    }

    /** ICU UCharCategory via VmIntlChar when available; ASCII+Latin-1 fallback. */
    private static function generalCategory(int $cp): int
    {
        if (\class_exists(\PHPCompiler\ext\intl\VmIntlChar::class, false)
            || \class_exists(\PHPCompiler\ext\intl\VmIntlChar::class)) {
            return \PHPCompiler\ext\intl\VmIntlChar::charType($cp);
        }

        return self::generalCategoryFallback($cp);
    }

    private static function generalCategoryFallback(int $cp): int
    {
        if (($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0xC0 && $cp <= 0xD6) || ($cp >= 0xD8 && $cp <= 0xDE)) {
            return 1; // Lu
        }
        if (($cp >= 0x61 && $cp <= 0x7A) || ($cp >= 0xDF && $cp <= 0xF6) || ($cp >= 0xF8 && $cp <= 0xFF)) {
            return 2; // Ll
        }
        if ($cp >= 0x30 && $cp <= 0x39) {
            return 9; // Nd
        }
        if (\in_array($cp, [0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x20], true)) {
            return 12; // Zs
        }
        if (0x5F === $cp) {
            return 22; // Pc
        }

        return 0;
    }

    private static function isLetterCategory(int $cp): bool
    {
        $t = self::generalCategory($cp);

        return $t >= 1 && $t <= 5;
    }

    private static function isMarkCategory(int $cp): bool
    {
        $t = self::generalCategory($cp);

        return $t >= 6 && $t <= 8;
    }

    private static function isNumberCategory(int $cp): bool
    {
        $t = self::generalCategory($cp);

        return $t >= 9 && $t <= 11;
    }

    private static function isDecimalDigitCategory(int $cp): bool
    {
        return 9 === self::generalCategory($cp);
    }

    private static function isSeparatorCategory(int $cp): bool
    {
        $t = self::generalCategory($cp);

        return $t >= 12 && $t <= 14;
    }

    private static function isOtherCategory(int $cp): bool
    {
        $t = self::generalCategory($cp);

        return 0 === $t || ($t >= 15 && $t <= 18);
    }

    private static function isPunctuationCategory(int $cp): bool
    {
        $t = self::generalCategory($cp);

        return ($t >= 19 && $t <= 23) || 28 === $t || 29 === $t;
    }

    private static function isSymbolCategory(int $cp): bool
    {
        $t = self::generalCategory($cp);

        return $t >= 24 && $t <= 27;
    }

    private static function isSpaceCategory(int $cp): bool
    {
        if (\class_exists(\PHPCompiler\ext\intl\VmIntlChar::class, false)
            || \class_exists(\PHPCompiler\ext\intl\VmIntlChar::class)) {
            return \PHPCompiler\ext\intl\VmIntlChar::isspace($cp);
        }

        return self::isSeparatorCategory($cp) || ($cp >= 0x09 && $cp <= 0x0D);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * Canonical decomposition for grapheme equality (php-src ICU parity; #6998).
 *
 * Minimal NFD tables for BMP precomposed characters used in grapheme_levenshtein().
 */
final class UnicodeCanonical
{
    /**
     * @return list<int>
     */
    public static function decompose(int $codepoint): array
    {
        if ($codepoint >= 0x00C0 && $codepoint <= 0x00FF) {
            return self::LATIN1_SUPPLEMENT[$codepoint] ?? [$codepoint];
        }
        if ($codepoint >= 0x0100 && $codepoint <= 0x017F) {
            return self::LATIN_EXTENDED_A[$codepoint] ?? [$codepoint];
        }
        if ($codepoint >= 0x0180 && $codepoint <= 0x024F) {
            return self::LATIN_EXTENDED_B[$codepoint] ?? [$codepoint];
        }

        return [$codepoint];
    }

    /**
     * @return list<int>
     */
    public static function utf8Codepoints(string $utf8): array
    {
        if ('' === $utf8) {
            return [];
        }
        // Byte walk — NestedJIT-safe (no preg_match_all; #28654 Normalizer AOT).
        $cps = [];
        $len = \strlen($utf8);
        $i = 0;
        while ($i < $len) {
            $b0 = \ord($utf8[$i]);
            if ($b0 < 0x80) {
                $cps[] = $b0;
                ++$i;
                continue;
            }
            $charLen = match (true) {
                ($b0 & 0xE0) === 0xC0 => 2,
                ($b0 & 0xF0) === 0xE0 => 3,
                ($b0 & 0xF8) === 0xF0 => 4,
                default => 0,
            };
            if (0 === $charLen || $i + $charLen > $len) {
                return [];
            }
            $cp = self::utf8CharToCodepoint(\substr($utf8, $i, $charLen));
            if (null === $cp) {
                return [];
            }
            $cps[] = $cp;
            $i += $charLen;
        }

        return $cps;
    }

    public static function codepointToUtf8(int $codepoint): string
    {
        if ($codepoint <= 0x7F) {
            return \chr($codepoint);
        }
        if ($codepoint <= 0x7FF) {
            return \chr(0xC0 | ($codepoint >> 6))
                .\chr(0x80 | ($codepoint & 0x3F));
        }
        if ($codepoint <= 0xFFFF) {
            return \chr(0xE0 | ($codepoint >> 12))
                .\chr(0x80 | (($codepoint >> 6) & 0x3F))
                .\chr(0x80 | ($codepoint & 0x3F));
        }

        return \chr(0xF0 | ($codepoint >> 18))
            .\chr(0x80 | (($codepoint >> 12) & 0x3F))
            .\chr(0x80 | (($codepoint >> 6) & 0x3F))
            .\chr(0x80 | ($codepoint & 0x3F));
    }

    public static function graphemeCanonicalKey(string $grapheme): string
    {
        return self::graphemeKeyFromCodepoints(self::utf8Codepoints($grapheme), false);
    }

    public static function graphemeCaseInsensitiveKey(string $grapheme): string
    {
        return self::graphemeKeyFromCodepoints(self::utf8Codepoints($grapheme), true);
    }

    public static function caseFold(int $codepoint): int
    {
        if ($codepoint >= 0x0041 && $codepoint <= 0x005A) {
            return $codepoint + 0x20;
        }
        if (isset(self::LATIN1_CASE_FOLD[$codepoint])) {
            return self::LATIN1_CASE_FOLD[$codepoint];
        }

        return $codepoint;
    }

    /**
     * NFD normalization (php-src ext/intl/normalizer — FORM_D; issue #5153).
     */
    public static function normalizeNfd(string $utf8): string
    {
        $codepoints = self::utf8Codepoints($utf8);
        if ([] === $codepoints && '' !== $utf8) {
            return $utf8;
        }
        $expanded = [];
        foreach ($codepoints as $cp) {
            foreach (self::decompose($cp) as $part) {
                $expanded[] = $part;
            }
        }
        self::canonicalOrder($expanded);

        return self::codepointsToUtf8($expanded);
    }

    /**
     * NFC normalization (php-src ext/intl/normalizer — FORM_C; issue #5153).
     */
    public static function normalizeNfc(string $utf8): string
    {
        $codepoints = self::utf8Codepoints(self::normalizeNfd($utf8));
        if ([] === $codepoints && '' !== $utf8) {
            return $utf8;
        }
        self::composeCanonical($codepoints);

        return self::codepointsToUtf8($codepoints);
    }

    public static function isNormalizedNfc(string $utf8): bool
    {
        return $utf8 === self::normalizeNfc($utf8);
    }

    public static function isNormalizedNfd(string $utf8): bool
    {
        return $utf8 === self::normalizeNfd($utf8);
    }

    /**
     * @param list<int> $codepoints
     */
    private static function codepointsToUtf8(array $codepoints): string
    {
        $out = '';
        foreach ($codepoints as $cp) {
            $out .= self::codepointToUtf8($cp);
        }

        return $out;
    }

    /**
     * @param list<int> $codepoints
     */
    private static function composeCanonical(array &$codepoints): void
    {
        $count = \count($codepoints);
        if ($count < 2) {
            return;
        }
        $compose = self::composeMap();
        $i = 0;
        while ($i < $count) {
            if (self::isCombiningMark($codepoints[$i])) {
                ++$i;
                continue;
            }
            $starter = $codepoints[$i];
            $j = $i + 1;
            while ($j < $count && self::isCombiningMark($codepoints[$j])) {
                $mark = $codepoints[$j];
                $key = $starter.','.$mark;
                if (isset($compose[$key])) {
                    $codepoints[$i] = $compose[$key];
                    \array_splice($codepoints, $j, 1);
                    $count = \count($codepoints);
                    $starter = $codepoints[$i];
                    continue;
                }
                ++$j;
            }
            ++$i;
        }
    }

    /** @var array<string, int>|null */
    private static ?array $composeMapCache = null;

    /** @return array<string, int> */
    private static function composeMap(): array
    {
        if (null !== self::$composeMapCache) {
            return self::$composeMapCache;
        }
        $map = [];
        foreach (self::LATIN1_SUPPLEMENT as $composed => $parts) {
            if (2 === \count($parts)) {
                $map[$parts[0].','.$parts[1]] = $composed;
            }
        }
        self::$composeMapCache = $map;

        return $map;
    }

    /**
     * @param list<int> $codepoints
     */
    private static function graphemeKeyFromCodepoints(array $codepoints, bool $caseInsensitive): string
    {
        $parts = [];
        foreach ($codepoints as $cp) {
            if ($caseInsensitive) {
                $cp = self::caseFold($cp);
            }
            foreach (self::decompose($cp) as $decomposed) {
                $parts[] = $decomposed;
            }
        }
        self::canonicalOrder($parts);

        return \implode(',', $parts);
    }

    /**
     * @param list<int> $codepoints
     */
    private static function canonicalOrder(array &$codepoints): void
    {
        $count = \count($codepoints);
        if ($count < 2) {
            return;
        }
        $i = 1;
        while ($i < $count) {
            $cp = $codepoints[$i];
            if (!self::isCombiningMark($cp)) {
                ++$i;
                continue;
            }
            $j = $i;
            while ($j > 0 && self::isCombiningMark($codepoints[$j - 1])) {
                --$j;
            }
            if ($j > 0 && $cp < $codepoints[$j]) {
                $tmp = $codepoints[$i];
                \array_splice($codepoints, $i, 1);
                \array_splice($codepoints, $j, 0, [$tmp]);
                if ($j < $i) {
                    $i = $j;
                }
                continue;
            }
            ++$i;
        }
    }

    private static function isCombiningMark(int $codepoint): bool
    {
        return ($codepoint >= 0x0300 && $codepoint <= 0x036F)
            || ($codepoint >= 0x1AB0 && $codepoint <= 0x1AFF)
            || ($codepoint >= 0x1DC0 && $codepoint <= 0x1DFF)
            || ($codepoint >= 0x20D0 && $codepoint <= 0x20FF)
            || ($codepoint >= 0xFE20 && $codepoint <= 0xFE2F);
    }

    private static function utf8CharToCodepoint(string $char): ?int
    {
        $bytes = $char;
        $b0 = \ord($bytes[0]);
        if ($b0 < 0x80) {
            return $b0;
        }
        $len = match (true) {
            ($b0 & 0xE0) === 0xC0 => 2,
            ($b0 & 0xF0) === 0xE0 => 3,
            ($b0 & 0xF8) === 0xF0 => 4,
            default => 0,
        };
        if (0 === $len || \strlen($bytes) !== $len) {
            return null;
        }
        $cp = $b0 & (0xFF >> ($len + 1));
        for ($i = 1; $i < $len; ++$i) {
            $bi = \ord($bytes[$i]);
            if (($bi & 0xC0) !== 0x80) {
                return null;
            }
            $cp = ($cp << 6) | ($bi & 0x3F);
        }

        return $cp;
    }

    /** @var array<int, list<int>> */
    private const LATIN1_SUPPLEMENT = [
        0x00C0 => [0x0041, 0x0300], 0x00C1 => [0x0041, 0x0301], 0x00C2 => [0x0041, 0x0302],
        0x00C3 => [0x0041, 0x0303], 0x00C4 => [0x0041, 0x0308], 0x00C5 => [0x0041, 0x030A],
        0x00C6 => [0x0041, 0x0306], 0x00C7 => [0x0043, 0x0327], 0x00C8 => [0x0045, 0x0300],
        0x00C9 => [0x0045, 0x0301], 0x00CA => [0x0045, 0x0302], 0x00CB => [0x0045, 0x0308],
        0x00CC => [0x0049, 0x0300], 0x00CD => [0x0049, 0x0301], 0x00CE => [0x0049, 0x0302],
        0x00CF => [0x0049, 0x0308], 0x00D0 => [0x0044, 0x0306], 0x00D1 => [0x004E, 0x0303],
        0x00D2 => [0x004F, 0x0300], 0x00D3 => [0x004F, 0x0301], 0x00D4 => [0x004F, 0x0302],
        0x00D5 => [0x004F, 0x0303], 0x00D6 => [0x004F, 0x0308], 0x00D8 => [0x004F, 0x0306],
        0x00D9 => [0x0055, 0x0300], 0x00DA => [0x0055, 0x0301], 0x00DB => [0x0055, 0x0302],
        0x00DC => [0x0055, 0x0308], 0x00DD => [0x0059, 0x0301], 0x00DE => [0x0054, 0x0308],
        0x00DF => [0x0073, 0x0073], 0x00E0 => [0x0061, 0x0300], 0x00E1 => [0x0061, 0x0301],
        0x00E2 => [0x0061, 0x0302], 0x00E3 => [0x0061, 0x0303], 0x00E4 => [0x0061, 0x0308],
        0x00E5 => [0x0061, 0x030A], 0x00E6 => [0x0061, 0x0306], 0x00E7 => [0x0063, 0x0327],
        0x00E8 => [0x0065, 0x0300], 0x00E9 => [0x0065, 0x0301], 0x00EA => [0x0065, 0x0302],
        0x00EB => [0x0065, 0x0308], 0x00EC => [0x0069, 0x0300], 0x00ED => [0x0069, 0x0301],
        0x00EE => [0x0069, 0x0302], 0x00EF => [0x0069, 0x0308], 0x00F0 => [0x0064, 0x0306],
        0x00F1 => [0x006E, 0x0303], 0x00F2 => [0x006F, 0x0300], 0x00F3 => [0x006F, 0x0301],
        0x00F4 => [0x006F, 0x0302], 0x00F5 => [0x006F, 0x0303], 0x00F6 => [0x006F, 0x0308],
        0x00F8 => [0x006F, 0x0306], 0x00F9 => [0x0075, 0x0300], 0x00FA => [0x0075, 0x0301],
        0x00FB => [0x0075, 0x0302], 0x00FC => [0x0075, 0x0308], 0x00FD => [0x0079, 0x0301],
        0x00FE => [0x0074, 0x0308], 0x00FF => [0x0079, 0x0308],
    ];

    /** @var array<int, list<int>> */
    private const LATIN_EXTENDED_A = [];

    /** @var array<int, list<int>> */
    private const LATIN_EXTENDED_B = [];

    /** @var array<int, int> */
    private const LATIN1_CASE_FOLD = [
        0x00C0 => 0x00E0, 0x00C1 => 0x00E1, 0x00C2 => 0x00E2, 0x00C3 => 0x00E3,
        0x00C4 => 0x00E4, 0x00C5 => 0x00E5, 0x00C6 => 0x00E6, 0x00C7 => 0x00E7,
        0x00C8 => 0x00E8, 0x00C9 => 0x00E9, 0x00CA => 0x00EA, 0x00CB => 0x00EB,
        0x00CC => 0x00EC, 0x00CD => 0x00ED, 0x00CE => 0x00EE, 0x00CF => 0x00EF,
        0x00D0 => 0x00F0, 0x00D1 => 0x00F1, 0x00D2 => 0x00F2, 0x00D3 => 0x00F3,
        0x00D4 => 0x00F4, 0x00D5 => 0x00F5, 0x00D6 => 0x00F6, 0x00D8 => 0x00F8,
        0x00D9 => 0x00F9, 0x00DA => 0x00FA, 0x00DB => 0x00FB, 0x00DC => 0x00FC,
        0x00DD => 0x00FD, 0x00DE => 0x00FE,
    ];
}

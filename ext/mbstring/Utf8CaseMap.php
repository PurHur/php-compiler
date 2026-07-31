<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * UTF-8 Unicode case mapping (php-src ext/mbstring/php_unicode.c + unicode_data.h; #7014, #24050, #25964).
 *
 * Full modes apply 1:N SpecialCasing / CaseFolding expansions; *_SIMPLE modes are 1:1 only
 * (multi-only mappings leave the codepoint unchanged — matches Zend MB_CASE_*_SIMPLE).
 *
 * Latin Extended-A (U+0100–U+017F) uses even/odd pairing ranges from UnicodeData; digraphs
 * with distinct titlecase (Ǆ→ǅ, …) use TITLE_SPECIAL per SpecialCasing.txt.
 */
final class Utf8CaseMap
{
    /** @var array<int, int> */
    private const UPPER_SPECIAL = [
        0xB5 => 0x39C, // micro sign -> Greek capital Mu
        0xFF => 0x178, // Latin small letter y with diaeresis -> capital
        0x131 => 0x49, // Latin small letter dotless i -> I
        0x17F => 0x53, // long s -> S
        0x3C2 => 0x3A3, // Greek final sigma -> Sigma
        // Latin digraphs (upper form) — SpecialCasing / UnicodeData (#25964)
        0x1C5 => 0x1C4, // ǅ -> Ǆ
        0x1C6 => 0x1C4, // ǆ -> Ǆ
        0x1C8 => 0x1C7, // ǈ -> Ǉ
        0x1C9 => 0x1C7, // ǉ -> Ǉ
        0x1CB => 0x1CA, // ǋ -> Ǌ
        0x1CC => 0x1CA, // ǌ -> Ǌ
        0x1F2 => 0x1F1, // ǲ -> Ǳ
        0x1F3 => 0x1F1, // ǳ -> Ǳ
    ];

    /**
     * Titlecase when it differs from uppercase (SpecialCasing digraphs).
     *
     * @var array<int, int>
     */
    private const TITLE_SPECIAL = [
        0x1C4 => 0x1C5, // Ǆ -> ǅ
        0x1C6 => 0x1C5, // ǆ -> ǅ
        0x1C7 => 0x1C8, // Ǉ -> ǈ
        0x1C9 => 0x1C8, // ǉ -> ǈ
        0x1CA => 0x1CB, // Ǌ -> ǋ
        0x1CC => 0x1CB, // ǌ -> ǋ
        0x1F1 => 0x1F2, // Ǳ -> ǲ
        0x1F3 => 0x1F2, // ǳ -> ǲ
    ];

    /** @var array<int, list<int>> Unicode full upper expansions (1:N codepoints). */
    private const UPPER_EXPANSION = [
        0xDF => [0x53, 0x53], // Latin small letter sharp S -> SS
        0xFB00 => [0x46, 0x46], // ﬀ
        0xFB01 => [0x46, 0x49], // ﬁ
        0xFB02 => [0x46, 0x4C], // ﬂ
        0xFB03 => [0x46, 0x46, 0x49], // ﬃ
        0xFB04 => [0x46, 0x46, 0x4C], // ﬄ
        0xFB05 => [0x53, 0x54], // ﬅ
        0xFB06 => [0x53, 0x54], // ﬆ
    ];

    /** @var array<int, list<int>> Unicode full lower expansions (1:N codepoints). */
    private const LOWER_EXPANSION = [
        0x130 => [0x69, 0x307], // LATIN CAPITAL LETTER I WITH DOT ABOVE -> i + combining dot
    ];

    /** Simple lower when full lower is 1:N only (php_unicode_tolower_simple). */
    private const LOWER_SIMPLE_SPECIAL = [
        0x130 => 0x69, // İ -> i (no combining dot)
    ];

    /** @var array<int, int> */
    private const LOWER_SPECIAL = [
        0x39C => 0xB5,
        0x178 => 0xFF,
        0x49 => 0x69, // I -> i (ASCII path handles most)
        0x3A3 => 0x3C3,
        // Latin digraphs (lower form)
        0x1C4 => 0x1C6, // Ǆ -> ǆ
        0x1C5 => 0x1C6, // ǅ -> ǆ
        0x1C7 => 0x1C9, // Ǉ -> ǉ
        0x1C8 => 0x1C9, // ǈ -> ǉ
        0x1CA => 0x1CC, // Ǌ -> ǌ
        0x1CB => 0x1CC, // ǋ -> ǌ
        0x1F1 => 0x1F3, // Ǳ -> ǳ
        0x1F2 => 0x1F3, // ǲ -> ǳ
    ];
    /**
     * Full case-fold expansions (CaseFolding.txt status F) — php_unicode_tofold_full.
     *
     * @var array<int, list<int>>
     */
    private const FOLD_EXPANSION = [
        0xDF => [0x73, 0x73], // ß -> ss
        0x130 => [0x69, 0x307], // İ -> i + combining dot
        0xFB00 => [0x66, 0x66], // ﬀ -> ff
        0xFB01 => [0x66, 0x69], // ﬁ -> fi
        0xFB02 => [0x66, 0x6C], // ﬂ -> fl
        0xFB03 => [0x66, 0x66, 0x69], // ﬃ -> ffi
        0xFB04 => [0x66, 0x66, 0x6C], // ﬄ -> ffl
        0xFB05 => [0x73, 0x74], // ﬅ -> st
        0xFB06 => [0x73, 0x74], // ﬆ -> st
    ];

    /** 1:1 fold mappings that differ from plain lower (CaseFolding C/S). */
    private const FOLD_SPECIAL = [
        0xB5 => 0x3BC, // µ -> μ
        0x17F => 0x73, // ſ -> s
        0x3C2 => 0x3C3, // ς -> σ
    ];

    /**
     * @return list<int>
     */
    public static function toUpperCodepoints(int $codepoint): array
    {
        if (isset(self::UPPER_EXPANSION[$codepoint])) {
            return self::UPPER_EXPANSION[$codepoint];
        }

        return [self::toUpper($codepoint)];
    }

    /**
     * Titlecase codepoints (SpecialCasing TITLE when distinct from UPPER; else upper).
     *
     * @return list<int>
     */
    public static function toTitleCodepoints(int $codepoint): array
    {
        if (isset(self::TITLE_SPECIAL[$codepoint])) {
            return [self::TITLE_SPECIAL[$codepoint]];
        }
        // Already in title form (ǅ/ǈ/ǋ/ǲ) — leave unchanged.
        if (
            0x1C5 === $codepoint || 0x1C8 === $codepoint
            || 0x1CB === $codepoint || 0x1F2 === $codepoint
        ) {
            return [$codepoint];
        }

        return self::toUpperCodepoints($codepoint);
    }

    /**
     * @return list<int>
     */
    public static function toLowerCodepoints(int $codepoint): array
    {
        if (isset(self::LOWER_EXPANSION[$codepoint])) {
            return self::LOWER_EXPANSION[$codepoint];
        }

        return [self::toLower($codepoint)];
    }
    /**
     * Full Unicode case fold (MB_CASE_FOLD) — php_unicode_tofold_full.
     *
     * @return list<int>
     */
    public static function toFoldCodepoints(int $codepoint): array
    {
        if (isset(self::FOLD_EXPANSION[$codepoint])) {
            return self::FOLD_EXPANSION[$codepoint];
        }

        return [self::toFoldSimple($codepoint)];
    }

    /** 1:1 upper (MB_CASE_UPPER_SIMPLE) — no multi-char SpecialCasing. */
    public static function toUpperSimple(int $codepoint): int
    {
        if (isset(self::UPPER_EXPANSION[$codepoint])) {
            return $codepoint;
        }

        return self::toUpper($codepoint);
    }

    /** 1:1 lower (MB_CASE_LOWER_SIMPLE). */
    public static function toLowerSimple(int $codepoint): int
    {
        if (isset(self::LOWER_SIMPLE_SPECIAL[$codepoint])) {
            return self::LOWER_SIMPLE_SPECIAL[$codepoint];
        }
        if (isset(self::LOWER_EXPANSION[$codepoint])) {
            return $codepoint;
        }

        return self::toLower($codepoint);
    }

    /**
     * 1:1 case fold (MB_CASE_FOLD_SIMPLE) — multi-only CaseFolding F entries unchanged.
     */
    public static function toFoldSimple(int $codepoint): int
    {
        if (isset(self::FOLD_EXPANSION[$codepoint])) {
            return $codepoint;
        }
        if (isset(self::FOLD_SPECIAL[$codepoint])) {
            return self::FOLD_SPECIAL[$codepoint];
        }

        return self::toLower($codepoint);
    }

    public static function toUpper(int $codepoint): int
    {
        if ($codepoint >= 0x61 && $codepoint <= 0x7A) {
            return $codepoint - 0x20;
        }
        if ($codepoint >= 0xE0 && $codepoint <= 0xFE) {
            return $codepoint - 0x20;
        }
        if (0xFF === $codepoint) {
            return 0x178;
        }
        $latinA = self::latinExtendedAUpper($codepoint);
        if (null !== $latinA) {
            return $latinA;
        }
        if ($codepoint >= 0x3B1 && $codepoint <= 0x3C1) {
            return $codepoint - 0x20;
        }
        if ($codepoint >= 0x3C3 && $codepoint <= 0x3CE) {
            return $codepoint - 0x20;
        }
        if (0x3C2 === $codepoint) {
            return 0x3A3;
        }
        if ($codepoint >= 0x430 && $codepoint <= 0x44F) {
            return $codepoint - 0x20;
        }
        if ($codepoint >= 0xFF41 && $codepoint <= 0xFF5A) {
            return $codepoint - 0x20;
        }

        return self::UPPER_SPECIAL[$codepoint] ?? $codepoint;
    }

    public static function toLower(int $codepoint): int
    {
        if ($codepoint >= 0x41 && $codepoint <= 0x5A) {
            return $codepoint + 0x20;
        }
        if ($codepoint >= 0xC0 && $codepoint <= 0xDE && 0xD7 !== $codepoint) {
            return $codepoint + 0x20;
        }
        if (0x178 === $codepoint) {
            return 0xFF;
        }
        $latinA = self::latinExtendedALower($codepoint);
        if (null !== $latinA) {
            return $latinA;
        }
        if ($codepoint >= 0x391 && $codepoint <= 0x3A1) {
            return $codepoint + 0x20;
        }
        if ($codepoint >= 0x3A3 && $codepoint <= 0x3AB) {
            return $codepoint + 0x20;
        }
        if ($codepoint >= 0x410 && $codepoint <= 0x42F) {
            return $codepoint + 0x20;
        }
        if ($codepoint >= 0xFF21 && $codepoint <= 0xFF3A) {
            return $codepoint + 0x20;
        }

        return self::LOWER_SPECIAL[$codepoint] ?? $codepoint;
    }

    /**
     * Latin Extended-A even/odd pairing (UnicodeData; excludes İ/ı/ĸ/ŉ/Ÿ/ſ handled elsewhere).
     */
    private static function latinExtendedAUpper(int $codepoint): ?int
    {
        if (
            ($codepoint >= 0x100 && $codepoint <= 0x12F)
            || ($codepoint >= 0x132 && $codepoint <= 0x137)
            || ($codepoint >= 0x14A && $codepoint <= 0x177)
        ) {
            return 0 === ($codepoint % 2) ? $codepoint : $codepoint - 1;
        }
        if (
            ($codepoint >= 0x139 && $codepoint <= 0x148)
            || ($codepoint >= 0x179 && $codepoint <= 0x17E)
        ) {
            return 1 === ($codepoint % 2) ? $codepoint : $codepoint - 1;
        }

        return null;
    }

    private static function latinExtendedALower(int $codepoint): ?int
    {
        if (
            ($codepoint >= 0x100 && $codepoint <= 0x12F)
            || ($codepoint >= 0x132 && $codepoint <= 0x137)
            || ($codepoint >= 0x14A && $codepoint <= 0x177)
        ) {
            return 0 === ($codepoint % 2) ? $codepoint + 1 : $codepoint;
        }
        if (
            ($codepoint >= 0x139 && $codepoint <= 0x148)
            || ($codepoint >= 0x179 && $codepoint <= 0x17E)
        ) {
            return 1 === ($codepoint % 2) ? $codepoint + 1 : $codepoint;
        }

        return null;
    }

    public static function isTitleDelimiter(int $codepoint): bool
    {
        return \in_array($codepoint, [
            0x20, 0x09, 0x0A, 0x0B, 0x0C, 0x0D,
            0x2D, 0x2010, 0x2011, 0x2012, 0x2013, 0x2014,
            0x2F, 0x5C,
        ], true);
    }
}

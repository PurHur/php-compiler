<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * UTF-8 Unicode simple case mapping (php-src ext/mbstring/libmbfl; subset for UTF-8/ASCII parity).
 */
final class Utf8CaseMap
{
    /** @var array<int, int> */
    private const UPPER_SPECIAL = [
        0xB5 => 0x39C, // micro sign -> Greek capital Mu
        0xFF => 0x178, // Latin small letter y with diaeresis -> capital
        0x130 => 0x49, // Turkish dotless i -> I
        0x131 => 0x49, // Turkish dotless i -> I (duplicate path)
        0x17F => 0x53, // long s -> S
        0x3C2 => 0x3A3, // Greek final sigma -> Sigma
    ];

    /** @var array<int, list<int>> Unicode simple upper expansions (1:N codepoints). */
    private const UPPER_EXPANSION = [
        0xDF => [0x53, 0x53], // Latin small letter sharp S -> SS (php-src libmbfl)
    ];

    /** @var array<int, int> */
    private const LOWER_SPECIAL = [
        0x39C => 0xB5,
        0x178 => 0xFF,
        0x49 => 0x69, // I -> i (ASCII path handles most)
        0x3A3 => 0x3C3,
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

    public static function isTitleDelimiter(int $codepoint): bool
    {
        return \in_array($codepoint, [
            0x20, 0x09, 0x0A, 0x0B, 0x0C, 0x0D,
            0x2D, 0x2010, 0x2011, 0x2012, 0x2013, 0x2014,
            0x2F, 0x5C,
        ], true);
    }
}

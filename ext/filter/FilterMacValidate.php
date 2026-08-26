<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * FILTER_VALIDATE_MAC — NestedJIT/AOT-safe unit (#35029, peer EMAIL #27068 / URL #27206).
 *
 * php-src: ext/filter/logical_filters.c — php_filter_validate_mac
 *
 * Keep free of VmFilter / `\preg_match` / NestedJIT `?string` / bool returns /
 * string-offset compound bool temps (NestedJIT STRING→BOOL assign #35029).
 * Host SSOT for compile-time fold: {@see VmFilter::isValidMacAddress()}.
 */
final class FilterMacValidate
{
    public static function isValid(string $input, ?string $expectedSeparator = null): bool
    {
        return 1 === self::check($input, $expectedSeparator);
    }

    /**
     * NestedJIT-safe 0/1 result for thin AOT dynamic bridges (#26853 / #35029).
     * $flags is unused (ABI peer of EMAIL/URL isValidInt); separator options stay on
     * the host {@see isValid()} path only.
     */
    public static function isValidInt(string $input, int $flags = 0): int
    {
        return self::check($input, null);
    }

    private static function check(string $input, ?string $expectedSeparator): int
    {
        $inputLen = \strlen($input);
        $tokens = 0;
        $length = 0;
        $sepOrd = 0;
        if (14 === $inputLen) {
            $tokens = 3;
            $length = 4;
            $sepOrd = 46; // '.'
        } elseif (17 === $inputLen) {
            $c2 = \ord($input[2]);
            if (45 === $c2) { // '-'
                $tokens = 6;
                $length = 2;
                $sepOrd = 45;
            } elseif (58 === $c2) { // ':'
                $tokens = 6;
                $length = 2;
                $sepOrd = 58;
            } else {
                return 0;
            }
        } else {
            return 0;
        }
        if (null !== $expectedSeparator) {
            if (1 !== \strlen($expectedSeparator) || \ord($expectedSeparator[0]) !== $sepOrd) {
                return 0;
            }
        }
        for ($i = 0; $i < $tokens; ++$i) {
            $offset = $i * ($length + 1);
            if ($i < $tokens - 1) {
                if (\ord($input[$offset + $length]) !== $sepOrd) {
                    return 0;
                }
            }
            if (0 === self::isValidHexTokenInt(\substr($input, $offset, $length))) {
                return 0;
            }
        }

        return 1;
    }

    private static function isValidHexTokenInt(string $token): int
    {
        $len = \strlen($token);
        if (0 === $len) {
            return 0;
        }
        for ($i = 0; $i < $len; ++$i) {
            $o = \ord($token[$i]);
            $isDigit = ($o >= 48 && $o <= 57) ? 1 : 0;
            $isLower = ($o >= 97 && $o <= 102) ? 1 : 0;
            $isUpper = ($o >= 65 && $o <= 70) ? 1 : 0;
            if (0 === $isDigit && 0 === $isLower && 0 === $isUpper) {
                return 0;
            }
        }

        return 1;
    }
}

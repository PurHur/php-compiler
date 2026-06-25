<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for spaceship (<=>) on runtime values (#9381, #9476, php-in-PHP).
 *
 * php-src: Zend/zend_operators.c — compare_function, zend_compare_arrays, zend_compare_objects
 * SSOT: {@see Variable::spaceshipCompare()}, {@see ObjectEntry::compareSpaceship()}, {@see HashTable::compareSpaceship()}
 */
final class CompareJitHelper
{
    /** @return int -1, 0, or 1 (LLVM i64 ABI) */
    public static function longSpaceship(int $left, int $right): int
    {
        return self::spaceshipNumeric($left, $right);
    }

    /** @return int -1, 0, or 1 (LLVM i64 ABI) */
    public static function doubleSpaceship(float $left, float $right): int
    {
        return self::spaceshipNumeric($left, $right);
    }

    /** @return int -1, 0, or 1 (LLVM i64 ABI) */
    public static function stringSpaceship(string $left, string $right): int
    {
        $cmp = strcmp($left, $right);

        return $cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0);
    }

    /** @param int $numOnLeft 1 when the numeric operand is on the left, 0 otherwise */
    public static function spaceshipNumberString(float $num, string $str, int $numOnLeft): int
    {
        if ('' === $str) {
            return 0 !== $numOnLeft ? 1 : -1;
        }
        if (is_numeric($str)) {
            $parsed = str_contains($str, '.') ? (float) $str : (int) $str;
            $cmp = self::spaceshipNumeric($num, $parsed);

            return 0 !== $numOnLeft ? $cmp : -$cmp;
        }

        return 0 !== $numOnLeft ? -1 : 1;
    }

    /** Compare boxed-value type tags when kinds differ (Zend type ordering). */
    public static function kindSpaceship(int $leftKind, int $rightKind): int
    {
        return self::longSpaceship($leftKind, $rightKind);
    }

    /** @return int -1, 0, or 1 (LLVM i64 ABI) */
    public static function objectSpaceship(ObjectEntry $left, ObjectEntry $right): int
    {
        return $left->compareSpaceship($right);
    }

    /** @return int -1, 0, or 1 (LLVM i64 ABI) */
    public static function hashtableSpaceship(HashTable $left, HashTable $right): int
    {
        return $left->compareSpaceship($right);
    }

    /** @param int|float $left */
    private static function spaceshipNumeric(int|float $left, int|float $right): int
    {
        if ((\is_float($left) && \is_nan($left)) || (\is_float($right) && \is_nan($right))) {
            return 1;
        }
        if ($left < $right) {
            return -1;
        }
        if ($left > $right) {
            return 1;
        }

        return 0;
    }
}

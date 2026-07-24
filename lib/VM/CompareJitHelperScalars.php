<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * NestedJIT-safe scalar spaceship helpers (#9381, #21109).
 *
 * ObjectEntry/HashTable-typed methods stay on {@see CompareJitHelper} for the VM only —
 * NestedJIT of those signatures emits invalid IR (value→pointer bitcasts, i32/i64 ABI).
 *
 * php-src: Zend/zend_operators.c — compare_function scalar paths
 */
final class CompareJitHelperScalars
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

    /**
     * Zend zendi_smart_strcmp — numeric strings compare as numbers (#22848).
     *
     * php-src: Zend/zend_operators.c — zendi_smart_strcmp / is_numeric_string_ex
     *
     * @return int -1, 0, or 1 (LLVM i64 ABI)
     */
    public static function stringSpaceship(string $left, string $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return self::spaceshipNumeric(self::numericFromString($left), self::numericFromString($right));
        }
        $cmp = strcmp($left, $right);

        return $cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0);
    }

    /** Match Variable::looseNumericFromString for whole-string is_numeric operands. */
    private static function numericFromString(string $s): int|float
    {
        if (Variable::isIntegralNumericString($s)) {
            return (int) $s;
        }

        return (float) $s;
    }

    /** @param int $numOnLeft 1 when the numeric operand is on the left, 0 otherwise */
    public static function spaceshipNumberString(float $num, string $str, int $numOnLeft): int
    {
        if ('' === $str) {
            return 0 !== $numOnLeft ? 1 : -1;
        }
        if (is_numeric($str)) {
            $parsed = Variable::isIntegralNumericString($str) ? (int) $str : (float) $str;
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

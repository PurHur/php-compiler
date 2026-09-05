<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Static bridge for BcMath\Number VM operators owned by ext/bcmath (#36204).
 *
 * lib/ must not import PHPCompiler\ext\bcmath; Module::init registers callables.
 *
 * php-src: ext/bcmath/bcmath.c — bcmath_number_do_operation / bcmath_number_compare.
 */
final class BcMathVmRuntimeSupport
{
    /** @var null|callable(Variable, Variable): (?int) */
    private static $tryCompare = null;

    /** @var null|callable(Variable, int, Variable, Variable, Context): bool */
    private static $tryDoOperation = null;

    /** @var null|callable(Variable, Variable, Context): bool */
    private static $tryUnaryMinus = null;

    public static function clear(): void
    {
        self::$tryCompare = null;
        self::$tryDoOperation = null;
        self::$tryUnaryMinus = null;
    }

    /** @param callable(Variable, Variable): (?int) $hook */
    public static function setTryCompare(callable $hook): void
    {
        self::$tryCompare = $hook;
    }

    /** @param callable(Variable, int, Variable, Variable, Context): bool $hook */
    public static function setTryDoOperation(callable $hook): void
    {
        self::$tryDoOperation = $hook;
    }

    /** @param callable(Variable, Variable, Context): bool $hook */
    public static function setTryUnaryMinus(callable $hook): void
    {
        self::$tryUnaryMinus = $hook;
    }

    public static function tryCompare(Variable $left, Variable $right): ?int
    {
        if (null === self::$tryCompare) {
            return null;
        }

        return (self::$tryCompare)($left, $right);
    }

    public static function tryDoOperation(
        Variable $result,
        int $opCode,
        Variable $left,
        Variable $right,
        Context $ctx
    ): bool {
        if (null === self::$tryDoOperation) {
            return false;
        }

        return (self::$tryDoOperation)($result, $opCode, $left, $right, $ctx);
    }

    public static function tryUnaryMinus(Variable $result, Variable $expr, Context $ctx): bool
    {
        if (null === self::$tryUnaryMinus) {
            return false;
        }

        return (self::$tryUnaryMinus)($result, $expr, $ctx);
    }
}

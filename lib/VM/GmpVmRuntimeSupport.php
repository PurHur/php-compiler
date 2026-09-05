<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Static bridge for GMP VM operators owned by ext/gmp (#36204).
 *
 * lib/ must not import PHPCompiler\ext\gmp; Module::init registers callables.
 *
 * php-src: ext/gmp/gmp.c — gmp_do_operation / gmp_compare / ZEND_BW_NOT.
 */
final class GmpVmRuntimeSupport
{
    /** @var null|callable(Variable, Variable): (?int) */
    private static $tryCompare = null;

    /** @var null|callable(Variable, int, Variable, Variable, Context): bool */
    private static $tryDoOperation = null;

    /** @var null|callable(Variable, Variable, Context): bool */
    private static $tryUnaryMinus = null;

    /** @var null|callable(Variable, Variable, Context): bool */
    private static $tryBitwiseNot = null;

    public static function clear(): void
    {
        self::$tryCompare = null;
        self::$tryDoOperation = null;
        self::$tryUnaryMinus = null;
        self::$tryBitwiseNot = null;
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

    /** @param callable(Variable, Variable, Context): bool $hook */
    public static function setTryBitwiseNot(callable $hook): void
    {
        self::$tryBitwiseNot = $hook;
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

    public static function tryBitwiseNot(Variable $result, Variable $expr, Context $ctx): bool
    {
        if (null === self::$tryBitwiseNot) {
            return false;
        }

        return (self::$tryBitwiseNot)($result, $expr, $ctx);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;

/**
 * Math / scalar-cast arg guards, typed-array count elision, and shared
 * string/math/array arg predicates (#36387).
 *
 * Pure math/round + effect-free void Native checks live in
 * {@see DiscardedPureCallElisionPureMathAndVoidNativeOps} (#36387).
 *
 * Extracted from {@see DiscardedPureCallElision} so the hub is not one monolith
 * TU on the gen-0 spine (split-TU / size-budget ratchet).
 *
 * Used via {@code use DiscardedPureCallElisionMathGuardAndVoidNativeOps;} on
 * {@see DiscardedPureCallElision}. Sibling MathAndHashOps / PureMath traits call
 * these helpers through {@code self::} on the composed hub class.
 *
 * No new C ABI. php-src: ext/standard/math.c, ext/standard/basic_functions.c
 * (intval/floatval/boolval), Zend/zend_builtin_functions.c (count).
 */
trait DiscardedPureCallElisionMathGuardAndVoidNativeOps
{
    /**
     * @param array<int, Variable> $callArgs
     */
    private static function baseConvertArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        switch ($nameLc) {
            case 'decbin':
            case 'dechex':
            case 'decoct':
                return !isset($callArgs[1])
                    && self::mathArgAllowsDiscardedElision($callArgs[0]);
            case 'bindec':
            case 'hexdec':
            case 'octdec':
                return !isset($callArgs[1])
                    && self::stringArgAllowsDiscardedElision($callArgs[0]);
            case 'base_convert':
                // string, long from_base∈[2,36], long to_base∈[2,36]
                if (
                    !isset($callArgs[1], $callArgs[2])
                    || isset($callArgs[3])
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !$callArgs[2] instanceof Variable
                ) {
                    return false;
                }

                return NoThrowCallElision::compileTimeRadixBaseInRange($callArgs[1])
                    && NoThrowCallElision::compileTimeRadixBaseInRange($callArgs[2]);
            default:
                return false;
        }
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function inetArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable || isset($callArgs[1])) {
            return false;
        }
        switch ($nameLc) {
            case 'ip2long':
            case 'inet_pton':
            case 'inet_ntop':
                return self::stringArgAllowsDiscardedElision($callArgs[0]);
            case 'long2ip':
                return self::mathArgAllowsDiscardedElision($callArgs[0]);
            default:
                return false;
        }
    }

    /**
     * Typed numeric scalars only. Single-array {@code min}/{@code max} stays live.
     * {@code fmin}/{@code fmax} need ≥2 args (ArgumentCountError otherwise).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function minMaxArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        if (('fmin' === $nameLc || 'fmax' === $nameLc) && \count($callArgs) < 2) {
            return false;
        }
        if (1 === \count($callArgs) && self::isTypedArrayArg($callArgs[0])) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::mathArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function checkdateArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1], $callArgs[2])
            || isset($callArgs[3])
        ) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::mathArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function clampArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1], $callArgs[2])
            || isset($callArgs[3])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
            || !$callArgs[2] instanceof Variable
        ) {
            return false;
        }
        // Value may be runtime typed numeric; bounds must be proven at compile time.
        if (!self::mathArgAllowsDiscardedElision($callArgs[0])) {
            return false;
        }
        $min = self::compileTimeNumericScalar($callArgs[1]);
        $max = self::compileTimeNumericScalar($callArgs[2]);
        if (null === $min || null === $max) {
            return false;
        }
        // php-src: NAN min/max → ValueError; min > max → ValueError.
        if ($min !== $min || $max !== $max) {
            return false;
        }

        return $min <= $max;
    }


    /**
     * @param array<int, Variable> $callArgs
     */
    private static function intdivArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }
        if (
            !self::mathArgAllowsDiscardedElision($callArgs[0])
            || !self::mathArgAllowsDiscardedElision($callArgs[1])
        ) {
            return false;
        }
        // Z_PARAM_LONG truncation — only proven compile-time longs are safe for
        // DivisionByZeroError / ArithmeticError proofs (float 0.5 → 0).
        $divisor = self::compileTimeLongScalar($callArgs[1]);
        if (null === $divisor || 0 === $divisor) {
            return false;
        }
        // php-src: PHP_INT_MIN / -1 → ArithmeticError; runtime dividend with
        // divisor -1 cannot prove ≠ INT_MIN.
        if (-1 === $divisor) {
            $dividend = self::compileTimeLongScalar($callArgs[0]);
            if (null === $dividend || \PHP_INT_MIN === $dividend) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compile-time int / finite-in-range float / numeric-string → zend_long
     * truncation for intdiv proofs (php-src {@code Z_PARAM_LONG}).
     *
     * KIND_VARIABLE (alloca) and boxed {@code __value__} slots are mutable at
     * runtime — {@see Variable::$compileTimeLong} is set on the first assign and
     * goes stale in loops. Folding {@code $s += $i} as {@code 0 + $i} made AOT
     * print the last {@code $i} (call-heavy / #36385 bench-gate; peer #32605).
     */
    private static function compileTimeLongScalar(Variable $arg): ?int
    {
        // Mutable storage: never treat as a foldable compile-time long.
        if (Variable::KIND_VARIABLE === $arg->kind) {
            return null;
        }
        if (JitValueBox::isValueOperand($arg)) {
            return null;
        }
        if (null !== $arg->compileTimeLong) {
            return $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeFloat) {
            $f = $arg->compileTimeFloat;
            if ($f !== $f || \is_infinite($f)) {
                return null;
            }
            if ($f > (float) \PHP_INT_MAX || $f < (float) \PHP_INT_MIN) {
                return null;
            }

            return (int) $f;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null === $lit || !is_numeric($lit)) {
            return null;
        }
        // Reject non-integer numeric strings that truncate to 0 unexpectedly
        // only via float path; (int)"1.5" === 1 matches Z_PARAM_LONG.
        $asFloat = (float) $lit;
        if ($asFloat > (float) \PHP_INT_MAX || $asFloat < (float) \PHP_INT_MIN) {
            return null;
        }

        return (int) $lit;
    }

    /**
     * Compile-time int/float/numeric-string scalar for clamp bound proofs.
     *
     * Same KIND_VARIABLE / boxed-value guard as {@see compileTimeLongScalar}
     * (#36385 / peer #32605).
     */
    private static function compileTimeNumericScalar(Variable $arg): ?float
    {
        if (Variable::KIND_VARIABLE === $arg->kind) {
            return null;
        }
        if (JitValueBox::isValueOperand($arg)) {
            return null;
        }
        if (null !== $arg->compileTimeLong) {
            return (float) $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeFloat) {
            return $arg->compileTimeFloat;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);

        return null !== $lit && is_numeric($lit) ? (float) $lit : null;
    }


    private static function scalarCastArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        switch ($nameLc) {
            case 'intval':
                if (!self::scalarCastValueArgAllowsDiscardedElision($callArgs[0])) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }
                if (
                    !$callArgs[1] instanceof Variable
                    || !self::mathArgAllowsDiscardedElision($callArgs[1])
                ) {
                    return false;
                }

                return !isset($callArgs[2]);
            case 'floatval':
            case 'doubleval':
            case 'boolval':
                if (isset($callArgs[1])) {
                    return false;
                }
                if ('boolval' === $nameLc && self::isTypedArrayArg($callArgs[0])) {
                    return true;
                }

                return self::scalarCastValueArgAllowsDiscardedElision($callArgs[0]);
            case 'strval':
                // Arrays warn; objects invoke __toString — scalars / null only.
                return !isset($callArgs[1])
                    && self::scalarCastValueArgAllowsDiscardedElision($callArgs[0]);
            default:
                return false;
        }
    }

    /**
     * Typed string / numeric / bool / null — no object / value-box / hashtable.
     */
    private static function scalarCastValueArgAllowsDiscardedElision(Variable $arg): bool
    {
        if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
            return true;
        }
        if (self::stringArgAllowsDiscardedElision($arg)) {
            return true;
        }

        return self::mathArgAllowsDiscardedElision($arg);
    }

    /**
     * Discarded {@code count}/{@code sizeof} on a typed array — php-src
     * {@code Zend/zend_builtin_functions.c} PHP_FUNCTION(count) only reads the
     * HashTable when the value is an array. Countable objects invoke user
     * {@code count()} and must stay live; null TypeErrors stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElideCountOnTypedArray(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('count' !== $name && 'sizeof' !== $name) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        if (!self::isTypedArrayArg($callArgs[0])) {
            return false;
        }
        if (isset($callArgs[1])) {
            // Optional $mode — null soft-deprecates (#31463); keep live.
            if (!$callArgs[1] instanceof Variable || !self::mathArgAllowsDiscardedElision($callArgs[1])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Already a string slot or compile-time string literal — no Z_PARAM_STR
     * coerce / null deprecate / {@code __toString}.
     */
    private static function stringArgAllowsDiscardedElision(Variable $arg): bool
    {
        if (null !== JitStringArg::compileTimeLiteral($arg)) {
            return true;
        }

        return Variable::TYPE_STRING === $arg->type;
    }

    /**
     * Typed hashtable, packed native array, or value-box proven to hold a
     * hashtable — not Countable / generic value-box.
     */
    private static function isTypedArrayArg(Variable $arg): bool
    {
        if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
            return true;
        }
        if (Variable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }
        if ($arg->compileTimeEmptyArrayLiteral) {
            return true;
        }

        // Locals like {@code $a = [1,2,3]} lower as TYPE_VALUE with
        // {@see Variable::$valueBoxHashtable} (#36386 array_key_first elision).
        return Variable::TYPE_VALUE === $arg->type && $arg->valueBoxHashtable;
    }

    /**
     * Already a numeric scalar — no Z_PARAM_* coerce / null deprecate / __toString.
     */
    private static function mathArgAllowsDiscardedElision(Variable $arg): bool
    {
        if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
            return false;
        }
        if (null !== $arg->compileTimeLong || null !== $arg->compileTimeFloat) {
            return true;
        }
        if (
            Variable::TYPE_NATIVE_LONG === $arg->type
            || Variable::TYPE_NATIVE_DOUBLE === $arg->type
            || Variable::TYPE_NATIVE_BOOL === $arg->type
        ) {
            return true;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);

        return null !== $lit && is_numeric($lit);
    }

}

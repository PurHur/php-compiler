<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Func\Internal as CoreFuncInternal;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\ext\standard\VmRoundMode;
use PHPCompiler\VM\Variable as VmVariable;

/**
 * Math / scalar-cast arg guards, typed-array count elision, pure math/round
 * discarded elision, and effect-free void Native constraint checks (#36387).
 *
 * Extracted from {@see DiscardedPureCallElision} so the hub is not one monolith
 * TU on the gen-0 spine (split-TU / size-budget ratchet).
 *
 * Used via {@code use DiscardedPureCallElisionMathGuardAndVoidNativeOps;} on
 * {@see DiscardedPureCallElision}. Sibling MathAndHashOps calls these helpers
 * through {@code self::} on the composed hub class.
 *
 * No new C ABI. php-src: ext/standard/math.c, ext/standard/basic_functions.c
 * (intval/floatval/boolval), Zend/zend_operators.c (arg constraint checks).
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
     * Discarded {@code abs}/{@code sqrt}/{@code floor}/…/{@code pi} on already-numeric
     * args (or zero-arg {@code pi}) — php-src {@code math.c} has no user handlers;
     * null soft-coercion deprecates so TYPE_NULL is excluded (peer strlen null).
     * Multi-arg builtins require exact arity ({@code ArgumentCountError} otherwise).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureMathNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureMathBuiltin($name)) {
            return false;
        }
        if ([] === $callArgs) {
            // pi() only — other math.c entries require at least one numeric arg.
            return 'pi' === $name;
        }
        if ('pi' === $name) {
            // Extra args stay live (ArgumentCountError).
            return false;
        }
        $argc = \count($callArgs);
        if ('log' === $name) {
            // log(num) or log(num, base) — both legal; other arities ArgumentCountError.
            if ($argc < 1 || $argc > 2) {
                return false;
            }
        } elseif ('round' === $name) {
            // round(num [, precision [, mode]]) — php-src math.c PHP_FUNCTION(round).
            // Excess argc → ArgumentCountError; mode ValueError gated below.
            if ($argc < 1 || $argc > 3) {
                return false;
            }
        } else {
            $required = self::pureMathBuiltinRequiredArgc($name);
            if (null !== $required && $argc !== $required) {
                return false;
            }
        }
        if ('round' === $name) {
            return self::roundArgsAllowDiscardedElision($callArgs);
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::mathArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exact argc for math.c builtins that reject wrong arity with
     * {@code ArgumentCountError}. Null only for {@code pi} (handled above);
     * {@code log} / {@code round} are gated separately (1..2 / 1..3).
     */
    private static function pureMathBuiltinRequiredArgc(string $nameLc): ?int
    {
        switch ($nameLc) {
            case 'hypot':
            case 'fmod':
            case 'atan2':
            case 'pow':
            case 'fpow':
            case 'fdiv':
            case 'nextafter':
                return 2;
            default:
                // Unary math.c entries (abs/sqrt/sin/…); excess argc stays live.
                return 1;
        }
    }

    /**
     * Discarded {@code round} — num + optional precision are Z_PARAM_DOUBLE /
     * LONG family (soft-null deprecates). Optional mode must not
     * {@code ValueError} under {@see CompilerVersion::supportsRoundingModeEnum}
     * (compile-time {@code PHP_ROUND_*} only); without the enum gate, typed
     * numeric mode is side-effect-free (php-src treats unknown ints as half-up).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function roundArgsAllowDiscardedElision(array $callArgs): bool
    {
        $argc = \count($callArgs);
        for ($i = 0; $i < $argc && $i < 2; ++$i) {
            if (!$callArgs[$i] instanceof Variable || !self::mathArgAllowsDiscardedElision($callArgs[$i])) {
                return false;
            }
        }
        if ($argc < 3) {
            return true;
        }
        if (!$callArgs[2] instanceof Variable) {
            return false;
        }

        return self::roundModeArgAllowsDiscardedElision($callArgs[2]);
    }

    /**
     * Mode arg for discarded {@code round} — soft-null / object / value-box stay
     * live; compile-time int/float must be a valid {@code PHP_ROUND_*} when the
     * RoundingMode enum profile is on; otherwise typed numeric scalars are OK.
     */
    private static function roundModeArgAllowsDiscardedElision(Variable $arg): bool
    {
        if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
            return false;
        }
        $compileTime = null;
        if (null !== $arg->compileTimeLong) {
            $compileTime = (int) $arg->compileTimeLong;
        } elseif (null !== $arg->compileTimeFloat) {
            // Z_PARAM_LONG truncates toward zero (peer intdiv float divisor).
            $compileTime = (int) $arg->compileTimeFloat;
        }
        if (null !== $compileTime) {
            if (!CompilerVersion::supportsRoundingModeEnum()) {
                return true;
            }

            return VmRoundMode::isValidLegacyIntMode($compileTime);
        }
        if (
            Variable::TYPE_NATIVE_LONG === $arg->type
            || Variable::TYPE_NATIVE_DOUBLE === $arg->type
            || Variable::TYPE_NATIVE_BOOL === $arg->type
        ) {
            // Runtime mode can still ValueError when RoundingMode is enforced.
            return !CompilerVersion::supportsRoundingModeEnum();
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $lit && is_numeric($lit)) {
            $asLong = (int) $lit;
            if (!CompilerVersion::supportsRoundingModeEnum()) {
                return true;
            }

            return VmRoundMode::isValidLegacyIntMode($asLong);
        }

        return false;
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

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function tryElideEffectFreeVoidNative(Context $context, ?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof Native) {
            return false;
        }
        $lc = strtolower($toCall->name);
        if (!isset($context->discardedCallElisionVoidNatives[$lc])) {
            return false;
        }

        return self::nativeArgsAllowElision($toCall, $callArgs, $context);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function nativeArgsAllowElision(Native $call, array $callArgs, Context $context): bool
    {
        if ([] !== $call->paramByRefByArg) {
            return false;
        }
        if (
            [] !== $call->paramIntersectionConstraintsByArg
            || [] !== $call->paramDnfConstraintsByArg
            || [] !== $call->paramClassConstraintsByArg
        ) {
            return false;
        }
        if (null !== $call->variadicArgIndex) {
            return false;
        }
        foreach ($call->paramTypeConstraintsByArg as $idx => $constraint) {
            if (!isset($callArgs[$idx]) || !$callArgs[$idx] instanceof Variable) {
                continue;
            }
            if (!self::compileTimeArgSatisfiesConstraint($callArgs[$idx], $constraint, $context->callerStrictTypes)) {
                return false;
            }
        }

        return true;
    }

    private static function compileTimeArgSatisfiesConstraint(
        Variable $arg,
        int $constraint,
        bool $strict
    ): bool {
        switch ($constraint) {
            case VmVariable::TYPE_STRING:
                if (null !== JitStringArg::compileTimeLiteral($arg)) {
                    return true;
                }
                if ($strict) {
                    return false;
                }

                return null !== $arg->compileTimeLong
                    || Variable::TYPE_NATIVE_LONG === $arg->type
                    || Variable::TYPE_NATIVE_DOUBLE === $arg->type
                    || Variable::TYPE_NATIVE_BOOL === $arg->type;
            case VmVariable::TYPE_INTEGER:
                if (null !== $arg->compileTimeLong) {
                    return true;
                }
                if (Variable::TYPE_NATIVE_LONG === $arg->type) {
                    return true;
                }
                if ($strict) {
                    return false;
                }
                if (Variable::TYPE_NATIVE_BOOL === $arg->type || Variable::TYPE_NATIVE_DOUBLE === $arg->type) {
                    return true;
                }
                $literal = JitStringArg::compileTimeLiteral($arg);

                return null !== $literal && is_numeric($literal);
            case VmVariable::TYPE_FLOAT:
                if (null !== $arg->compileTimeFloat) {
                    return true;
                }
                if (Variable::TYPE_NATIVE_DOUBLE === $arg->type) {
                    return true;
                }
                if ($strict) {
                    return false;
                }

                return null !== $arg->compileTimeLong
                    || Variable::TYPE_NATIVE_LONG === $arg->type
                    || (null !== ($lit = JitStringArg::compileTimeLiteral($arg)) && is_numeric($lit));
            case VmVariable::TYPE_BOOL:
                if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
                    return true;
                }
                if ($strict) {
                    return false;
                }

                return null !== $arg->compileTimeLong
                    || Variable::TYPE_NATIVE_LONG === $arg->type;
            default:
                return false;
        }
    }
}

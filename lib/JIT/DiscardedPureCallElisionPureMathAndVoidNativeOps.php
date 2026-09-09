<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Func\Internal as CoreFuncInternal;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\ext\standard\VmRoundMode;
use PHPCompiler\VM\Variable as VmVariable;

/**
 * Pure math/round discarded elision and effect-free void Native constraint
 * checks (#36387).
 *
 * Extracted from {@see DiscardedPureCallElisionMathGuardAndVoidNativeOps} so
 * the gen-0 spine gets another TU and the math-arg-guard file stays under the
 * size-budget ratchet. External call sites keep using
 * {@code DiscardedPureCallElision::…} (trait methods on the hub class).
 *
 * Shared arg predicates ({@code mathArgAllowsDiscardedElision},
 * {@code stringArgAllowsDiscardedElision}) stay on
 * {@see DiscardedPureCallElisionMathGuardAndVoidNativeOps}.
 *
 * Used via {@code use DiscardedPureCallElisionPureMathAndVoidNativeOps;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: ext/standard/math.c (abs/sqrt/floor/…/pi/round),
 * Zend/zend_operators.c (arg constraint checks for void Natives).
 */
trait DiscardedPureCallElisionPureMathAndVoidNativeOps
{
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

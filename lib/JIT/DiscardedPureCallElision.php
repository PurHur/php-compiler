<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\VM\Variable as VmVariable;

/**
 * Elide discarded calls to compile-time-pure builtins (#23483 / #36386 call-overhead).
 *
 * php-src: ZPP may still run user-visible coercions; here we only fold cases that are
 * side-effect-free (literal / typed-string strlen, type.c predicates, empty void
 * user functions). Soft-null strlen coercions are NOT elided — they emit deprecations.
 */
final class DiscardedPureCallElision
{
    /**
     * @param array<int, Variable> $callArgs
     */
    public static function tryElide(Context $context, ?Call $toCall, array $callArgs): bool
    {
        if (self::tryElidePureTypePredicate($toCall)) {
            return true;
        }
        if (self::tryElideStrlenNoSideEffect($toCall, $callArgs)) {
            return true;
        }

        return self::tryElideEffectFreeVoidNative($context, $toCall, $callArgs);
    }

    /**
     * Discarded {@code is_int}/{@code is_string}/… — php-src {@code type.c} only
     * reads the zval type tag (peer {@see NoThrowCallElision}).
     */
    private static function tryElidePureTypePredicate(?Call $toCall): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }

        return NoThrowCallElision::isPureTypePredicateBuiltin(strtolower($toCall->getName()));
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function tryElideStrlenNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('strlen' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        $arg = $callArgs[0];
        // Literal or already-a-string slot — no Z_PARAM_STR coercion / deprecate.
        if (null !== JitStringArg::compileTimeLiteral($arg)) {
            return true;
        }

        return Variable::TYPE_STRING === $arg->type;
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

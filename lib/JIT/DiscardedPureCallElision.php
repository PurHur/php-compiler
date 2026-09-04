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
 * side-effect-free (literal / typed-string strlen/ord, typed-numeric chr, type.c
 * predicates, math.c on already-numeric args, empty void user functions). Soft-null
 * strlen / ord / chr / math coercions are NOT elided — they emit deprecations (PHP 8.1+).
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
        if (self::tryElideOrdNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElideChrNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureMathNoSideEffect($toCall, $callArgs)) {
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
     * Discarded {@code ord()} on a typed / literal string — php-src
     * {@code string.c} {@code PHP_FUNCTION(ord)} only reads the first byte;
     * soft int→string / null coerce deprecates (PHP 8.1+) so those stay live
     * (peer {@see tryElideStrlenNoSideEffect}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElideOrdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('ord' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        $arg = $callArgs[0];
        if (null !== JitStringArg::compileTimeLiteral($arg)) {
            return true;
        }

        return Variable::TYPE_STRING === $arg->type;
    }

    /**
     * Discarded {@code chr()} on already-numeric args — php-src
     * {@code string.c} {@code PHP_FUNCTION(chr)} is Z_PARAM_LONG; null soft
     * coerce deprecates so TYPE_NULL is excluded (peer math discarded elision).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElideChrNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('chr' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }

        return self::mathArgAllowsDiscardedElision($callArgs[0]);
    }

    /**
     * Discarded {@code abs}/{@code sqrt}/{@code floor}/… on already-numeric args —
     * php-src {@code math.c} has no user handlers; null soft-coercion deprecates
     * so TYPE_NULL is excluded (peer strlen null).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureMathNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureMathBuiltin(strtolower($toCall->getName()))) {
            return false;
        }
        if ([] === $callArgs) {
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

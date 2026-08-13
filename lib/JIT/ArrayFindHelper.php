<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitArrayElem;
use PHPCompiler\JIT\Builtin\ArrayFindRuntime;
use PHPLLVM\Value;

/**
 * JIT lowering orchestration for array_find / array_find_key / array_any / array_all (#3073, #17674).
 *
 * Predicate walks delegate to {@see ArrayFindRuntime} + {@see \PHPCompiler\ext\standard\ArrayFindJitHelper} PHP.
 */
final class ArrayFindHelper
{
    private const MODE_FIND = 0;
    private const MODE_FIND_KEY = 1;
    private const MODE_ANY = 2;
    private const MODE_ALL = 3;

    public static function buildFindArray(
        Context $context,
        Variable $array,
        Variable $callback,
        ?Variable $strictArg = null,
        ?Value $strictI1Override = null
    ): Value {
        $strictI1 = $strictI1Override ?? self::resolveStrictI1($context, $strictArg);

        return self::buildFromArray(
            $context,
            $array,
            $callback,
            self::MODE_FIND,
            $strictI1
        );
    }

    public static function buildFindKeyArray(
        Context $context,
        Variable $array,
        Variable $callback,
        ?Variable $strictArg = null,
        ?Value $strictI1Override = null
    ): Value {
        $strictI1 = $strictI1Override ?? self::resolveStrictI1($context, $strictArg);

        return self::buildFromArray(
            $context,
            $array,
            $callback,
            self::MODE_FIND_KEY,
            $strictI1
        );
    }

    public static function buildAnyArray(
        Context $context,
        Variable $array,
        Variable $callback,
        ?Variable $strictArg = null,
        ?Value $strictI1Override = null,
        bool $unaryInternalUsesKey = false,
    ): Value {
        $strictI1 = $strictI1Override ?? self::resolveStrictI1($context, $strictArg);

        return self::buildFromArray(
            $context,
            $array,
            $callback,
            self::MODE_ANY,
            $strictI1,
            $unaryInternalUsesKey
        );
    }

    public static function buildAllArray(
        Context $context,
        Variable $array,
        Variable $callback,
        ?Variable $strictArg = null,
        ?Value $strictI1Override = null,
        bool $unaryInternalUsesKey = false,
    ): Value {
        $strictI1 = $strictI1Override ?? self::resolveStrictI1($context, $strictArg);

        return self::buildFromArray(
            $context,
            $array,
            $callback,
            self::MODE_ALL,
            $strictI1,
            $unaryInternalUsesKey
        );
    }

    /**
     * array_all()/array_any() on compile-time empty inline [] — vacuous true/false without callback (#11729).
     */
    public static function vacuousAnyAllIfCompileTimeEmpty(Context $context, Variable $array, bool $all): ?Value
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type) && 0 === $array->nextFreeElement) {
            return $context->constantFromBool($all);
        }
        if ($array->compileTimeEmptyArrayLiteral) {
            return $context->constantFromBool($all);
        }

        return null;
    }

    private static function buildFromArray(
        Context $context,
        Variable $array,
        Variable $callback,
        int $mode,
        Value $strictI1,
        bool $unaryInternalUsesKey = false,
    ): Value {
        JitArrayElem::requireArrayArg($context, $array, self::functionNameForMode($mode));
        if (self::MODE_ANY === $mode || self::MODE_ALL === $mode) {
            $vacuous = self::vacuousAnyAllIfCompileTimeEmpty(
                $context,
                $array,
                self::MODE_ALL === $mode
            );
            if (null !== $vacuous) {
                return $vacuous;
            }
        }
        $function = self::functionNameForMode($mode);
        // Catchable TypeError under AOT try/catch (#17133 / #30624) — do not throw PHP TypeError at compile time.
        if (ArrayFindCallbackPolicy::isJitNullCallback($callback)) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                ArrayFindCallbackPolicy::invalidCallbackTypeError($function)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_null_cb_te_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if (!ArrayFindCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(ArrayFindCallbackPolicy::jitRejectionMessage());
        }
        if (ArrayFindCallbackPolicy::isClosureJitLowerable($callback)) {
            return ArrayFindRuntime::walkClosure(
                $context,
                $array,
                $callback,
                $mode,
                $strictI1,
                $unaryInternalUsesKey
            );
        }
        if (ArrayReduceCallbackPolicy::isJitLowerableScalar(
            $callback->type,
            $callback->isNullConstant,
            $callback->compileTimeString
        )) {
            return ArrayFindRuntime::walk(
                $context,
                $array,
                $callback,
                $mode,
                $strictI1,
                $unaryInternalUsesKey
            );
        }

        throw new \LogicException(ArrayFindCallbackPolicy::jitRejectionMessage());
    }

    private static function resolveStrictI1(Context $context, ?Variable $strictArg): Value
    {
        if (null === $strictArg) {
            return $context->constantFromBool(false);
        }

        return JitBoolArg::lower($context, $strictArg, 'array_find() strict');
    }

    private static function functionNameForMode(int $mode): string
    {
        return match ($mode) {
            self::MODE_FIND => 'array_find',
            self::MODE_FIND_KEY => 'array_find_key',
            self::MODE_ANY => 'array_any',
            self::MODE_ALL => 'array_all',
            default => 'array_find',
        };
    }

}

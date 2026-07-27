<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\ArrayFindCallbackPolicy;
use PHPCompiler\VM\Variable;

/**
 * Invoke single-argument array callbacks from VM builtins (array_find family).
 */
final class VmArrayValueCallback
{
    /** array_find family null callback → TypeError (ext/standard/array.c; #17133). */
    public static function requirePredicateCallback(
        Variable $callback,
        string $function,
        int $argNum = 2
    ): void {
        VmArraySortCallback::requireCallback($callback, $function, $argNum);
    }

    /**
     * array_find-family callback validation before iteration (#17133, ext/standard/array.c).
     */
    public static function requireCallback(
        Frame $frame,
        Variable $callback,
        string $function,
        int $argNum = 2,
    ): void {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $callback->type) {
            throw new \TypeError(ArrayFindCallbackPolicy::invalidCallbackTypeError($function, $argNum));
        }
        if (VmClosureCall::isClosure($callback)) {
            return;
        }
        if (Variable::TYPE_STRING === $callback->type) {
            self::requireStringCallback($frame, $callback, $function, $argNum);

            return;
        }

        throw new \TypeError(ArrayFindCallbackPolicy::invalidCallbackTypeError($function, $argNum));
    }

    /**
     * Invoke array_find-family predicate — php-src php_array_find passes (value, key) for
     * array_find/array_find_key/array_any/array_all; forward-profile array_any_key/array_all_key
     * closures get (key, value); internal builtins get arity-trimmed operands (#17300, #17599).
     */
    public static function invokePredicate(
        Frame $frame,
        Variable $callback,
        Variable $value,
        Variable $key,
        string $function = 'array_find',
        int $argNum = 2,
    ): Variable {
        self::requireCallback($frame, $callback, $function, $argNum);
        $callback = $callback->resolveIndirect();
        $keyFirst = self::callbackKeyFirst($function);
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException(
                    'array callback requires VM context in this compiler build'
                );
            }

            return VmClosureCall::invoke(
                $frame->vmContext,
                VmClosureCall::resolve($callback),
                $keyFirst ? $key : $value,
                $keyFirst ? $value : $key,
            );
        }
        $name = $callback->toString();
        try {
            $fn = VmInternalCall::resolveStringCallback($name);

            return VmArrayFindInternalInvoke::invoke(
                $fn,
                $value,
                $key,
                self::unaryInternalUsesKey($function),
                $keyFirst,
            );
        } catch (\LogicException) {
            // Not a registered string builtin — try a user-defined function.
        }
        if (null === $frame->vmContext) {
            throw new \LogicException(
                'array callback requires VM context in this compiler build'
            );
        }
        $fn = VmUserCall::resolveStringCallback($frame->vmContext, $name);

        return VmUserCall::invokeTwo(
            $frame->vmContext,
            $fn,
            $keyFirst ? $key : $value,
            $keyFirst ? $value : $key,
        );
    }

    /**
     * Forward-profile array_any_key/array_all_key pass key before value; php-src array_find_key uses (value, key) (#17599).
     */
    public static function callbackKeyFirst(string $function): bool
    {
        return 'array_any_key' === $function
            || 'array_all_key' === $function;
    }

    /**
     * Forward-profile array_all_key/array_any_key unary internal predicates inspect keys (#17300).
     */
    private static function unaryInternalUsesKey(string $function): bool
    {
        return 'array_all_key' === $function || 'array_any_key' === $function;
    }

    private static function requireStringCallback(
        Frame $frame,
        Variable $callback,
        string $function,
        int $argNum,
    ): void {
        $name = $callback->toString();
        try {
            VmInternalCall::resolveStringCallback($name);

            return;
        } catch (\LogicException) {
            // Not a registered string builtin — try a user-defined function.
        }
        if (null === $frame->vmContext) {
            throw new \LogicException($function.'() requires VM context in this compiler build');
        }
        try {
            VmUserCall::resolveStringCallback($frame->vmContext, $name);
        } catch (\LogicException) {
            throw new \TypeError(
                ArrayFindCallbackPolicy::invalidStringCallbackTypeError($function, $name, $argNum)
            );
        }
    }

    public static function isTruthy(Variable $result): bool
    {
        return boolval::isTruthy($result->resolveIndirect());
    }

    /**
     * array_find-family predicate success (php-src ext/standard/array.c — php_is_true vs strict true).
     */
    public static function predicateMatches(Variable $result, bool $strict): bool
    {
        $result = $result->resolveIndirect();
        if (!$strict) {
            return self::isTruthy($result);
        }

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    /**
     * php-src array_find/array_find_key/array_any/array_all — exactly two args (#23875).
     *
     * @param list<Variable> $calledArgs
     *
     * @throws \ArgumentCountError
     */
    public static function requireExactTwoArgs(array $calledArgs, string $fn): void
    {
        $argc = \count($calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 2 arguments, %d given',
                $fn,
                $argc
            ));
        }
    }

    /**
     * Forward-profile array_all_key/array_any_key optional $strict (#15704).
     *
     * @param list<Variable> $calledArgs
     */
    public static function parseOptionalStrictArg(array $calledArgs, string $fn, int $minArgs = 2, int $maxArgs = 3): bool
    {
        $argc = \count($calledArgs);
        if ($argc < $minArgs || $argc > $maxArgs) {
            throw new \LogicException(\sprintf(
                '%s() requires %d or %d arguments in this compiler build',
                $fn,
                $minArgs,
                $maxArgs
            ));
        }
        if ($argc === $maxArgs) {
            return $calledArgs[$maxArgs - 1]->resolveIndirect()->toBool();
        }

        return false;
    }
}

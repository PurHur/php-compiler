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
     * Invoke array_find-family predicate with php-src (value, key) callback args (PHP 8.4 array.c).
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
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException(
                    'array callback requires VM context in this compiler build'
                );
            }

            return VmClosureCall::invoke(
                $frame->vmContext,
                VmClosureCall::resolve($callback),
                $value,
                $key,
            );
        }
        $name = $callback->toString();
        try {
            $fn = VmInternalCall::resolveStringCallback($name);

            return VmInternalCall::invoke($fn, $value, $key);
        } catch (\LogicException) {
            // Not a registered string builtin — try a user-defined function.
        }
        if (null === $frame->vmContext) {
            throw new \LogicException(
                'array callback requires VM context in this compiler build'
            );
        }
        $fn = VmUserCall::resolveStringCallback($frame->vmContext, $name);

        return VmUserCall::invokeTwo($frame->vmContext, $fn, $value, $key);
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
        if (!VmCallable::isCallable($frame->vmContext, $callback)) {
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

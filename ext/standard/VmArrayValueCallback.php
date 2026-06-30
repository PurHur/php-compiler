<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Invoke single-argument array callbacks from VM builtins (array_find family).
 */
final class VmArrayValueCallback
{
    /**
     * Invoke array_find-family predicate with php-src (value, key) callback args (PHP 8.4 array.c).
     */
    public static function invokePredicate(
        Frame $frame,
        Variable $callback,
        Variable $value,
        Variable $key,
    ): Variable {
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
        if (Variable::TYPE_STRING !== $callback->type) {
            throw new \LogicException(
                'array callback must be a string builtin, user function, or closure in this compiler build'
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

    public static function isTruthy(Variable $result): bool
    {
        return boolval::isTruthy($result->resolveIndirect());
    }
}

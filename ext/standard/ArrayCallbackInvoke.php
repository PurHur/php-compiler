<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\Func\PHP;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Variable;

/**
 * Shared helper: invoke a resolved callback with ($value, $key) — used by
 * array_find, array_find_key, array_any, array_all (PHP 8.4).
 */
final class ArrayCallbackInvoke
{
    public static function invoke(
        Frame $frame,
        ?ClosureState $closure,
        ?Internal $internal,
        ?PHP $userFn,
        ?Variable $general,
        Variable $value,
        Variable $key,
    ): Variable {
        $context = $frame->vmContext;

        if (null !== $general) {
            return VmCallable::invokeAsWithScope(
                'array_find',
                $context,
                $frame,
                $general,
                $value,
                $key
            );
        }

        if (null !== $closure) {
            return VmClosureCall::invoke($context, $closure, $value, $key);
        }

        if (null !== $internal) {
            return VmInternalCall::invoke($internal, $value, $key);
        }

        return $context->runtime->vm->invokePhpFunction($userFn, $value, $key);
    }
}

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
 *
 * Internal string builtins are arity-trimmed like php-src (#30228 / #17300):
 * unary predicates such as {@see is_int} receive only the value.
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
            // php-src zend_call_function — do not pass key to 1-arg internals (#30228).
            return VmArrayFindInternalInvoke::invoke($internal, $value, $key, false, false);
        }

        return $context->runtime->vm->invokePhpFunction($userFn, $value, $key);
    }
}

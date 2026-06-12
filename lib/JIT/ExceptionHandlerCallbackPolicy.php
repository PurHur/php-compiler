<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable;

/**
 * set_exception_handler() callback validation (issue #6243, basic_functions.c).
 */
final class ExceptionHandlerCallbackPolicy
{
    public const DEFERRED_KINDS = 'closures, array callables, and invokable objects';

    /**
     * Zend set_exception_handler() invalid callback TypeError (#6243).
     */
    public static function invalidCallbackTypeError(): string
    {
        return 'set_exception_handler(): Argument #1 ($callback) must be a valid callback or null, no array or string given';
    }

    public static function isJitLowerable(Variable $callback): bool
    {
        if ($callback->isNullConstant) {
            return true;
        }

        return Variable::TYPE_STRING === $callback->type && null !== $callback->compileTimeString;
    }

    public static function jitRejectionMessage(): string
    {
        return 'set_exception_handler() callback must be null or a compile-time string function name in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred (#4311)';
    }
}

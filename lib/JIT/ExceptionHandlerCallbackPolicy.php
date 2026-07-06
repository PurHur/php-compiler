<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * set_exception_handler() callback validation (issue #6243, basic_functions.c).
 */
final class ExceptionHandlerCallbackPolicy
{
    public const DEFERRED_KINDS = 'closures, array callables, and invokable objects';

    /** Scalar/container types Zend rejects before callable dispatch (#16693). */
    public static function isPhpSrcInvalidCallbackType(int $type): bool
    {
        return \in_array($type, [
            VMVariable::TYPE_INTEGER,
            VMVariable::TYPE_BOOLEAN,
            VMVariable::TYPE_FLOAT,
            VMVariable::TYPE_ARRAY,
            VMVariable::TYPE_OBJECT,
        ], true);
    }

    /** Compile-time scalars/containers that must TypeError, not defer (#16693). */
    public static function isJitPhpSrcInvalidCallbackType(Variable $callback): bool
    {
        if (null !== $callback->closureCall) {
            return false;
        }
        $type = $callback->type;

        return \in_array($type, [
            Variable::TYPE_NATIVE_LONG,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::TYPE_NATIVE_BOOL,
            Variable::TYPE_HASHTABLE,
            Variable::TYPE_OBJECT,
        ], true) || 0 !== ($type & Variable::IS_NATIVE_ARRAY);
    }

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

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred array_find / array_find_key / array_any / array_all callbacks (issue #3073).
 *
 * JIT/AOT: compile-time string stdlib builtins, compile-time string user functions in the
 * compile unit, and closure/arrow predicates ([#142](https://github.com/PurHur/php-compiler/issues/142)).
 */
final class ArrayFindCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'array_find family: string builtins, user functions, closure/arrow; [class, method] callables deferred';

    public const DEFERRED_KINDS = 'array callables and invokable objects';

    public const JIT_SUBSET =
        'compile-time string stdlib builtin names, user-function names in this compile unit, or closure/arrow callbacks';

    public static function isClosureJitLowerable(JITVariable $callback): bool
    {
        return null !== $callback->closureCall;
    }

    public static function isJitLowerable(JITVariable $callback): bool
    {
        if (JITVariable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
            return false;
        }
        if (self::isClosureJitLowerable($callback)) {
            return true;
        }
        if (JITVariable::TYPE_STRING === $callback->type && null !== $callback->compileTimeString) {
            return true;
        }

        return ArrayReduceCallbackPolicy::isJitLowerable($callback);
    }

    public static function isJitNullCallback(JITVariable $callback): bool
    {
        return JITVariable::TYPE_NULL === $callback->type || $callback->isNullConstant;
    }

    /**
     * Zend array_find-family invalid callback TypeError (#17133, ext/standard/array.c).
     */
    public static function invalidCallbackTypeError(string $function, int $argNum = 2): string
    {
        return \sprintf(
            '%s(): Argument #%d ($callback) must be a valid callback, no array or string given',
            $function,
            $argNum
        );
    }

    /**
     * Zend undefined string callback TypeError (#17133).
     */
    public static function invalidStringCallbackTypeError(string $function, string $name, int $argNum = 2): string
    {
        return \sprintf(
            '%s(): Argument #%d ($callback) must be a valid callback, function "%s" not found or invalid function name',
            $function,
            $argNum,
            $name
        );
    }

    public static function isVmSupportedType(int $type): bool
    {
        return VMVariable::TYPE_STRING === $type;
    }

    public static function jitRejectionMessage(): string
    {
        return 'array_find() callback must be '.self::JIT_SUBSET
            .' for JIT/AOT in this compiler build; '.self::DEFERRED_KINDS.' are deferred (#3073)';
    }

    public static function vmRejectionMessage(): string
    {
        return 'array callback must be a string builtin, user function, or closure in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred';
    }
}

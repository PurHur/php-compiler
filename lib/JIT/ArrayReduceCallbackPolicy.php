<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred array_reduce() callback forms (issue #1213).
 *
 * VM lowers compile-time string user-function names. Closures and other callables stay
 * deferred until user-function / callable JIT lands ([#142](https://github.com/PurHur/php-compiler/issues/142)).
 */
final class ArrayReduceCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'array_reduce callbacks: compile-time string user-function names VM-only; closures deferred';

    public const DEFERRED_KINDS = 'closures, array callables, and invokable objects';

    public const JIT_SUBSET = 'compile-time string user-function names in this compile unit';

    public static function isJitLowerable(JITVariable $callback): bool
    {
        return self::isJitLowerableScalar(
            $callback->type,
            $callback->isNullConstant,
            $callback->compileTimeString
        );
    }

    public static function isJitLowerableScalar(int $type, bool $isNullConstant, ?string $compileTimeString): bool
    {
        return JITVariable::TYPE_STRING === $type && null !== $compileTimeString;
    }

    public static function isVmSupportedType(int $type): bool
    {
        return VMVariable::TYPE_STRING === $type;
    }

    public static function jitRejectionMessage(): string
    {
        return 'array_reduce() callback must be '.self::JIT_SUBSET
            .' for JIT/AOT in this compiler build; '.self::DEFERRED_KINDS.' are deferred (#142)';
    }

    public static function vmRejectionMessage(): string
    {
        return 'array_reduce() callback must be a string user-function name in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred';
    }

    /**
     * Zend array_reduce() invalid callback TypeError (#6679, ext/standard/array.c).
     */
    public static function invalidCallbackTypeError(): string
    {
        return 'array_reduce(): Argument #2 ($callback) must be a valid callback, no array or string given';
    }

    /**
     * Zend undefined string user-function callback TypeError (#6679).
     */
    public static function invalidStringCallbackTypeError(string $name): string
    {
        return sprintf(
            'array_reduce(): Argument #2 ($callback) must be a valid callback, function "%s" not found or invalid function name',
            $name
        );
    }
}

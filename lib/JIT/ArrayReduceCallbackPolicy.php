<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred array_reduce() callback forms (issue #1213, #3531, #6683).
 *
 * VM validation lives in {@see \PHPCompiler\ext\standard\VmReduceCallback}. JIT/AOT lowers
 * compile-time string user-function names and closure/arrow callbacks with native returns
 * ([#142](https://github.com/PurHur/php-compiler/issues/142), #3531).
 */
final class ArrayReduceCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'array_reduce callbacks: string user functions + closure/arrow (int/double); array callables deferred';

    public const DEFERRED_KINDS = 'array callables and invokable objects';

    public const JIT_SUBSET = 'compile-time string user-function names or closure/arrow callbacks';

    public static function isClosureJitLowerable(JITVariable $callback): bool
    {
        return null !== $callback->closureCall;
    }

    public static function isJitLowerable(JITVariable $callback): bool
    {
        if (self::isClosureJitLowerable($callback)) {
            return true;
        }

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
        return \in_array($type, [VMVariable::TYPE_STRING], true);
    }

    public static function jitRejectionMessage(): string
    {
        return 'array_reduce() callback must be '.self::JIT_SUBSET
            .' for JIT/AOT in this compiler build; '.self::DEFERRED_KINDS.' are deferred (#142)';
    }

    /**
     * Thin standalone AOT has Context but not Runtime->vm — closures cannot run (#24117 / #23540).
     */
    public static function thinAotClosureRejectionMessage(): string
    {
        return 'array_reduce() with a Closure callback is not supported by thin standalone AOT in this build; '
            .'use bin/vm.php or bin/jit.php';
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

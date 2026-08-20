<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;

/**
 * array_filter() callback validation messages (ext/standard/array.c; #10782).
 *
 * VM validation lives in {@see \PHPCompiler\ext\standard\VmArrayFilterCallback}.
 * JIT/AOT: default-mode Closure callbacks via {@see ArrayFilterLlvm} (#32672).
 */
final class ArrayFilterCallbackPolicy
{
    public const JIT_SUBSET = 'null callback or closure/arrow callback (ARRAY_FILTER_USE_VALUE)';

    public static function isClosureJitLowerable(JITVariable $callback): bool
    {
        return null !== $callback->closureCall;
    }

    public static function isJitLowerable(JITVariable $callback): bool
    {
        return self::isClosureJitLowerable($callback);
    }

    public static function jitRejectionMessage(): string
    {
        return 'array_filter() callback must be '.self::JIT_SUBSET
            .' for JIT/AOT in this compiler build; string/array callables are deferred';
    }

    /**
     * Zend array_filter() invalid callback TypeError (ext/standard/array.c).
     */
    public static function invalidCallbackTypeError(): string
    {
        return 'array_filter(): Argument #2 ($callback) must be a valid callback or null, no array or string given';
    }

    /**
     * Zend array_filter() malformed array callback TypeError.
     */
    public static function invalidArrayCallbackTypeError(): string
    {
        return 'array_filter(): Argument #2 ($callback) must be a valid callback or null, array callback must have exactly two members';
    }

    /**
     * Zend undefined string callback TypeError.
     */
    public static function invalidStringCallbackTypeError(string $name): string
    {
        return sprintf(
            'array_filter(): Argument #2 ($callback) must be a valid callback or null, function "%s" not found or invalid function name',
            $name
        );
    }
}

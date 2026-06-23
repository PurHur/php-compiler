<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * array_filter() callback validation messages (ext/standard/array.c; #10782).
 *
 * VM validation lives in {@see \PHPCompiler\ext\standard\VmArrayFilterCallback}.
 */
final class ArrayFilterCallbackPolicy
{
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

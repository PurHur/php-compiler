<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * register_shutdown_function() callback validation (issue #6245, basic_functions.c).
 */
final class ShutdownCallbackPolicy
{
    /**
     * Zend register_shutdown_function() invalid callback TypeError (#6245).
     */
    public static function invalidCallbackTypeError(): string
    {
        return 'register_shutdown_function(): Argument #1 ($callback) must be a valid callback, no array or string given';
    }
}

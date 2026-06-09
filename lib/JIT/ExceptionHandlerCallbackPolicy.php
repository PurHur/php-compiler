<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * set_exception_handler() callback validation (issue #6243, basic_functions.c).
 */
final class ExceptionHandlerCallbackPolicy
{
    /**
     * Zend set_exception_handler() invalid callback TypeError (#6243).
     */
    public static function invalidCallbackTypeError(): string
    {
        return 'set_exception_handler(): Argument #1 ($callback) must be a valid callback or null, no array or string given';
    }
}

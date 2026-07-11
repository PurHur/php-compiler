<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * header_register_callback() callback validation (head.c; #14789).
 */
final class HeaderCallbackPolicy
{
    public static function invalidCallbackTypeError(): string
    {
        return 'header_register_callback(): Argument #1 ($callback) must be a valid callback, no array or string given';
    }
}

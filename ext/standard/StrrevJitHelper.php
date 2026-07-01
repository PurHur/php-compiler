<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strrev() for compiled JIT/AOT modules (#14566, php-in-PHP).
 *
 * SSOT: {@see VmString::strrev()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strrev)
 */
final class StrrevJitHelper
{
    public static function strrevArgv(string $string): string
    {
        return VmString::strrev($string);
    }
}

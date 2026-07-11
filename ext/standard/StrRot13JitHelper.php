<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_rot13() for compiled JIT/AOT modules (#14896, php-in-PHP).
 *
 * SSOT: {@see VmString::strRot13()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_rot13)
 */
final class StrRot13JitHelper
{
    public static function rot13Argv(string $input): string
    {
        return VmString::strRot13($input);
    }
}

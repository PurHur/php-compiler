<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stripslashes() for compiled JIT/AOT modules (#14742, php-in-PHP).
 *
 * SSOT: {@see VmString::stripslashes()}
 * php-src: ext/standard/stripslashes.c — PHP_FUNCTION(stripslashes)
 */
final class StripslashesJitHelper
{
    public static function stripslashesArgv(string $string): string
    {
        return VmString::stripslashes($string);
    }
}

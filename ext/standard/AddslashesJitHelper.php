<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * addslashes() for compiled JIT/AOT modules (#14741, php-in-PHP).
 *
 * SSOT: {@see VmString::addslashes()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(addslashes)
 */
final class AddslashesJitHelper
{
    public static function addslashesArgv(string $string): string
    {
        return VmString::addslashes($string);
    }
}

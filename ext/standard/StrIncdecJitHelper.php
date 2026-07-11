<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_increment()/str_decrement() for compiled JIT/AOT modules (#14850, php-in-PHP).
 *
 * SSOT: {@see VmString::strIncrement()} / {@see VmString::strDecrement()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_increment), PHP_FUNCTION(str_decrement)
 */
final class StrIncdecJitHelper
{
    public static function incrementArgv(string $string): string
    {
        return VmString::strIncrement($string);
    }

    public static function decrementArgv(string $string): string
    {
        return VmString::strDecrement($string);
    }
}

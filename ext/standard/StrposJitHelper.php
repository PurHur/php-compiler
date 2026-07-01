<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strpos()/stripos() for compiled JIT/AOT modules (#14766, php-in-PHP).
 *
 * SSOT: {@see VmString::strpos()} / {@see VmString::stripos()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strpos), PHP_FUNCTION(stripos)
 */
final class StrposJitHelper
{
    public static function strposArgv(string $haystack, string $needle, int $offset): int
    {
        $pos = VmString::strpos($haystack, $needle, $offset);

        return false === $pos ? 0 : $pos;
    }

    public static function striposArgv(string $haystack, string $needle, int $offset): int
    {
        $pos = VmString::stripos($haystack, $needle, $offset);

        return false === $pos ? 0 : $pos;
    }
}

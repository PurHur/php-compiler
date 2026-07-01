<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strrpos()/strripos() for compiled JIT/AOT modules (#14752, php-in-PHP).
 *
 * SSOT: {@see VmString::strrpos()} / {@see VmString::strripos()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strrpos), PHP_FUNCTION(strripos)
 */
final class StrrposJitHelper
{
    public static function strrposArgv(string $haystack, string $needle, int $offset): int
    {
        $pos = VmString::strrpos($haystack, $needle, $offset);

        return false === $pos ? 0 : $pos;
    }

    public static function strriposArgv(string $haystack, string $needle, int $offset): int
    {
        $pos = VmString::strripos($haystack, $needle, $offset);

        return false === $pos ? 0 : $pos;
    }
}

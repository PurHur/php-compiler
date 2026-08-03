<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strrpos()/strripos() host/unit SSOT wrapper (#14752).
 *
 * AOT/JIT runtime uses {@see \PHPCompiler\JIT\Builtin\StringStrrpos} value-box ABI.
 * Kept for spine/unit shrink gates; NestedJIT of this file under thin AOT is avoided
 * when possible — prefer compile-time {@see VmString} fold.
 *
 * SSOT: {@see VmString::strrpos()} / {@see VmString::strripos()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strrpos), PHP_FUNCTION(strripos)
 */
final class StrrposJitHelper
{
    public const NOT_FOUND = -1;

    public static function strrposArgv(string $haystack, string $needle, int $offset): int
    {
        $pos = VmString::strrpos($haystack, $needle, $offset);

        return false === $pos ? self::NOT_FOUND : $pos;
    }

    public static function strriposArgv(string $haystack, string $needle, int $offset): int
    {
        $pos = VmString::strripos($haystack, $needle, $offset);

        return false === $pos ? self::NOT_FOUND : $pos;
    }
}

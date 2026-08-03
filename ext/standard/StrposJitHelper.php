<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strpos()/stripos() host/unit SSOT wrapper (#14766).
 *
 * AOT/JIT runtime search goes through {@see \PHPCompiler\JIT\Builtin\StringStrpos}
 * + {@see JitStringSearch} (not this helper's NestedJIT). Kept for spine/unit shrink gates.
 *
 * SSOT: {@see VmString::strpos()} / {@see VmString::stripos()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strpos), PHP_FUNCTION(stripos)
 */
final class StrposJitHelper
{
    public const NOT_FOUND = -1;

    public static function strposArgv(string $haystack, string $needle, int $offset): int
    {
        $pos = VmString::strpos($haystack, $needle, $offset);

        return false === $pos ? self::NOT_FOUND : $pos;
    }

    public static function striposArgv(string $haystack, string $needle, int $offset): int
    {
        $pos = VmString::stripos($haystack, $needle, $offset);

        return false === $pos ? self::NOT_FOUND : $pos;
    }
}

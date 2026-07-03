<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * memcmp() / strncmp() for compiled JIT/AOT modules (#15364, php-in-PHP).
 *
 * SSOT: {@see VmString::memcmp()} / {@see VmString::strncmp()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(memcmp|strncmp)
 */
final class NCompareJitHelper
{
    public static function memcmpArgv(string $a, string $b, int $length): int
    {
        return VmString::memcmp($a, $b, $length);
    }

    public static function strncmpArgv(string $a, string $b, int $length): int
    {
        return VmString::strncmp($a, $b, $length);
    }
}

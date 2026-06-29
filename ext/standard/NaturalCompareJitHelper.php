<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strnatcmp() / strnatcasecmp() for compiled JIT/AOT modules (#13535, php-in-PHP).
 *
 * SSOT: {@see VmString::strnatcmp()} / {@see VmString::strnatcasecmp()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strnatcmp) / strnatcasecmp
 */
final class NaturalCompareJitHelper
{
    public static function strnatcmpArgv(string $a, string $b): int
    {
        return VmString::strnatcmp($a, $b);
    }

    public static function strnatcasecmpArgv(string $a, string $b): int
    {
        return VmString::strnatcasecmp($a, $b);
    }
}

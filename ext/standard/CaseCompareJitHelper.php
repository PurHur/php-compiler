<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strcasecmp() / strncasecmp() for compiled JIT/AOT modules (#15225, php-in-PHP).
 *
 * SSOT: {@see VmString::strcasecmp()} / {@see VmString::strncasecmp()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strcasecmp|strncasecmp)
 */
final class CaseCompareJitHelper
{
    public static function strcasecmpArgv(string $a, string $b): int
    {
        return VmString::strcasecmp($a, $b);
    }

    public static function strncasecmpArgv(string $a, string $b, int $length): int
    {
        return VmString::strncasecmp($a, $b, $length);
    }
}

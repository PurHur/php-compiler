<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strcoll() for compiled JIT/AOT modules (#13566 phase 2, php-in-PHP).
 *
 * SSOT: {@see VmLocaleCollate::strcoll()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strcoll)
 */
final class StrcollJitHelper
{
    public static function strcollArgv(string $a, string $b): int
    {
        return VmLocaleCollate::strcoll($a, $b);
    }
}

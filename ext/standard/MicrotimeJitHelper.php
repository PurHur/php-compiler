<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * microtime() semantics reference for VM (#9181, php-in-PHP).
 *
 * Thin AOT/JIT emit uses libc gettimeofday via {@see \PHPCompiler\JIT\Builtin\StringMicrotime}
 * (NestedJIT of this helper orphans insert blocks / segfaults — #26930).
 * SSOT: {@see VmDate::microtime()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(microtime)
 */
final class MicrotimeJitHelper
{
    public static function microtimeFloat(): float
    {
        return VmDate::microtime(true);
    }

    public static function microtimeString(): string
    {
        return VmDate::microtime(false);
    }
}

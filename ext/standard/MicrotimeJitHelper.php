<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * microtime() for compiled JIT/AOT modules (#29405, php-in-PHP).
 *
 * Leaf is `@microtime` → NestedJIT whitelist {@see microtime} →
 * {@see \PHPCompiler\JIT\Builtin\StringMicrotime} thin gettimeofday leaf
 * (gethostname #29364 / getenv #29313 shape).
 * php-src: ext/standard/microtime.c — PHP_FUNCTION(microtime)
 */
final class MicrotimeJitHelper
{
    public static function microtimeFloat(): float
    {
        return (float) @\microtime(true);
    }

    public static function microtimeString(): string
    {
        return (string) @\microtime(false);
    }
}

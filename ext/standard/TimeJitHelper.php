<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * time() for compiled JIT/AOT modules (#30332, php-in-PHP).
 *
 * Leaf is `@time` → NestedJIT whitelist {@see time} →
 * {@see \PHPCompiler\JIT\Builtin\StringTime} thin libc time(2) leaf
 * (microtime #29405 / gethostname #29364 shape).
 * php-src: ext/date/php_date.c — PHP_FUNCTION(time)
 */
final class TimeJitHelper
{
    public static function timeArgv(): int
    {
        return (int) @\time();
    }
}

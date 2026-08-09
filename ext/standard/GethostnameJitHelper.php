<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gethostname() for compiled JIT/AOT modules (#21166, #28544, #29364, php-in-PHP).
 *
 * Leaf is `@gethostname` → NestedJIT whitelist {@see gethostname} →
 * {@see \PHPCompiler\JIT\Builtin\StringGethostname} → {@see JitGethostnameKernel}
 * /proc+/etc open/read (no kernel Internal; getenv #29313 / putenv #29334 shape).
 * Empty string on failure so callers can box to false (getcwd #10451 shape).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(gethostname)
 */
final class GethostnameJitHelper
{
    public static function resolveJit(): string
    {
        $host = @\gethostname();

        return \is_string($host) ? $host : '';
    }
}

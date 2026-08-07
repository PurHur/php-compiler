<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gethostname() for compiled JIT/AOT modules (#21166, #28544, php-in-PHP).
 *
 * Kernel path: {@see phpc_gethostname_kernel} → {@see VmHostPure} / /proc NestedJIT leaf.
 * Empty string on failure so callers can box to false (getcwd #10451 shape).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(gethostname)
 */
final class GethostnameJitHelper
{
    public static function resolveJit(): string
    {
        $host = \phpc_gethostname_kernel();

        return \is_string($host) ? $host : '';
    }
}

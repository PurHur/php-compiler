<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_geteuid() for compiled JIT/AOT modules (#30767, php-in-PHP).
 *
 * Leaf is `@posix_geteuid` → NestedJIT whitelist {@see posix_geteuid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixGeteuidJit} thin libc geteuid(2) leaf via
 * {@see JitPosixGeteuidKernel} (posix_getuid #30744 shape).
 * Keep NestedJIT TU small — no {@see VmPosix} pull (#579 stubs).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_geteuid)
 */
final class PosixGeteuidJitHelper
{
    public static function geteuidArgv(): int
    {
        return (int) @\posix_geteuid();
    }
}

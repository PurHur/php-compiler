<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_getuid() for compiled JIT/AOT modules (#30744, php-in-PHP).
 *
 * Leaf is `@posix_getuid` → NestedJIT whitelist {@see posix_getuid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixGetuidJit} thin libc getuid(2) leaf via
 * {@see JitPosixGetuidKernel} (posix_getppid #30728 shape).
 * Keep NestedJIT TU small — no {@see VmPosix} pull (#579 stubs).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getuid)
 */
final class PosixGetuidJitHelper
{
    public static function getuidArgv(): int
    {
        return (int) @\posix_getuid();
    }
}

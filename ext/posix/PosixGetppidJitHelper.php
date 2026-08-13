<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_getppid() for compiled JIT/AOT modules (#30728, php-in-PHP).
 *
 * Leaf is `@posix_getppid` → NestedJIT whitelist {@see posix_getppid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixGetppidJit} thin libc getppid(2) leaf via
 * {@see JitPosixGetppidKernel} (posix_getpid #30696 shape).
 * Keep NestedJIT TU small — no {@see VmPosix} pull (#579 stubs).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getppid)
 */
final class PosixGetppidJitHelper
{
    public static function getppidArgv(): int
    {
        return (int) @\posix_getppid();
    }
}

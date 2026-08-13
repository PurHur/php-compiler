<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_getgid() for compiled JIT/AOT modules (#30803, php-in-PHP).
 *
 * Leaf is `@posix_getgid` → NestedJIT whitelist {@see posix_getgid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixGetgidJit} thin libc getgid(2) leaf via
 * {@see JitPosixGetgidKernel} (posix_geteuid #30767 shape).
 * Keep NestedJIT TU small — no {@see VmPosix} pull (#579 stubs).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getgid)
 */
final class PosixGetgidJitHelper
{
    public static function getgidArgv(): int
    {
        return (int) @\posix_getgid();
    }
}

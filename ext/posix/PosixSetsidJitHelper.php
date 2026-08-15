<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_setsid() for compiled JIT/AOT modules (#31235, php-in-PHP).
 *
 * Leaf is `@posix_setsid` → NestedJIT whitelist {@see posix_setsid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixSetsidJit} thin libc setsid(2) leaf via
 * {@see JitPosixSetsidKernel} (posix_getpid #30696 / posix_setuid #31038 shape).
 * Keep NestedJIT TU small — no {@see VmPosix} pull (#579 stubs).
 * Host false → -1 so ABI stays int64 (peer VM setsid SSOT).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setsid)
 */
final class PosixSetsidJitHelper
{
    public static function setsidArgv(): int
    {
        $sid = @\posix_setsid();

        return false === $sid ? -1 : (int) $sid;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_setpgid() for compiled JIT/AOT modules (#31235, php-in-PHP).
 *
 * Leaf is `@posix_setpgid` → NestedJIT whitelist {@see posix_setpgid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixSetpgidJit} thin libc setpgid(2) leaf via
 * {@see JitPosixSetpgidKernel} (posix_setuid #31038 / posix_setgid #31066 shape).
 * Keep NestedJIT TU small — no {@see VmPosix} pull (#579 stubs).
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (#20603 / HashEquals i32 ABI).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setpgid)
 */
final class PosixSetpgidJitHelper
{
    /** @return int 1 on success, 0 on failure */
    public static function setpgidArgv(int $pid, int $pgid): int
    {
        return @\posix_setpgid($pid, $pgid) ? 1 : 0;
    }
}

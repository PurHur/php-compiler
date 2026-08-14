<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_setegid() for compiled JIT/AOT modules (#31066, php-in-PHP).
 *
 * Leaf is `@posix_setegid` → NestedJIT whitelist {@see posix_setegid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixSetegidJit} thin libc setegid(2) leaf via
 * {@see JitPosixSetegidKernel} (posix_setuid #31038 shape).
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (#20603 / HashEquals i32 ABI).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setegid)
 */
final class PosixSetegidJitHelper
{
    /** @return int 1 on success, 0 on failure */
    public static function setegidArgv(int $gid): int
    {
        return @\posix_setegid($gid) ? 1 : 0;
    }
}

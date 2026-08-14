<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_setgid() for compiled JIT/AOT modules (#31066, php-in-PHP).
 *
 * Leaf is `@posix_setgid` → NestedJIT whitelist {@see posix_setgid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixSetgidJit} thin libc setgid(2) leaf via
 * {@see JitPosixSetgidKernel} (posix_setuid #31038 shape).
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (#20603 / HashEquals i32 ABI).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setgid)
 */
final class PosixSetgidJitHelper
{
    /** @return int 1 on success, 0 on failure */
    public static function setgidArgv(int $gid): int
    {
        return @\posix_setgid($gid) ? 1 : 0;
    }
}

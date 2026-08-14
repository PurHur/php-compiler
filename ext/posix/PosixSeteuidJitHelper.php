<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_seteuid() for compiled JIT/AOT modules (#31066, php-in-PHP).
 *
 * Leaf is `@posix_seteuid` → NestedJIT whitelist {@see posix_seteuid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixSeteuidJit} thin libc seteuid(2) leaf via
 * {@see JitPosixSeteuidKernel} (posix_setuid #31038 shape).
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (#20603 / HashEquals i32 ABI).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_seteuid)
 */
final class PosixSeteuidJitHelper
{
    /** @return int 1 on success, 0 on failure */
    public static function seteuidArgv(int $uid): int
    {
        return @\posix_seteuid($uid) ? 1 : 0;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_setuid() for compiled JIT/AOT modules (#31038, php-in-PHP).
 *
 * Leaf is `@posix_setuid` → NestedJIT whitelist {@see posix_setuid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixSetuidJit} thin libc setuid(2) leaf via
 * {@see JitPosixSetuidKernel} (posix_getegid #30986 / proc_nice #30615 shape).
 * Keep NestedJIT TU small — no {@see VmPosix} pull (#579 stubs).
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (#20603 / HashEquals i32 ABI).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setuid)
 */
final class PosixSetuidJitHelper
{
    /** @return int 1 on success, 0 on failure */
    public static function setuidArgv(int $uid): int
    {
        return @\posix_setuid($uid) ? 1 : 0;
    }
}

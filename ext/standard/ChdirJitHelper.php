<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chdir() for compiled JIT/AOT modules (#21147, php-in-PHP).
 *
 * Kernel path: {@see phpc_chdir_kernel}; VM SSOT remains {@see VmFs::chdir()}.
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (bool boxes have no readLong arm and always yield 0; see #20603 / HashEquals i32 ABI).
 * Warning on failure stays on the VM {@see chdir_} path (prior JIT libc path also
 * returned bare bool without TriggerError — keep NestedJIT leaf small).
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chdir)
 */
final class ChdirJitHelper
{
    /** @return int 1 on success, 0 on failure */
    public static function invokeArgv(string $path): int
    {
        return \phpc_chdir_kernel($path) ? 1 : 0;
    }
}

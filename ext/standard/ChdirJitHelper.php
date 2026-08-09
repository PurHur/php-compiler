<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chdir() for compiled JIT/AOT modules (#21147, #29219, php-in-PHP).
 *
 * Leaf is `@chdir` → NestedJIT whitelist {@see chdir_} →
 * {@see \PHPCompiler\JIT\Builtin\StringChdir::invokeNestedLeaf} (no kernel).
 * Keep NestedJIT TU small — no {@see VmFs} pull (#579 stubs; Rename #29141 shape).
 * Null-byte paths are rejected by {@see JitFilestatArg::lowerFilename}.
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (#20603 / HashEquals i32 ABI).
 * Warning on failure stays on the VM {@see chdir_} path.
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chdir)
 */
final class ChdirJitHelper
{
    /** @return int 1 on success, 0 on failure */
    public static function invokeArgv(string $path): int
    {
        return @\chdir($path) ? 1 : 0;
    }
}

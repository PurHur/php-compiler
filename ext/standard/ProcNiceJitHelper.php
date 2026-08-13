<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * proc_nice() for compiled JIT/AOT modules (#30615, php-in-PHP).
 *
 * Leaf is `@proc_nice` → NestedJIT whitelist {@see proc_nice} →
 * {@see \PHPCompiler\JIT\Builtin\StringProcNice::invokeNestedLeaf} (no kernel).
 * Keep NestedJIT TU small — no {@see VmProcNicePure} pull (#579 stubs; chroot #30558 shape).
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (#20603 / HashEquals i32 ABI).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(proc_nice)
 */
final class ProcNiceJitHelper
{
    /** @return int 1 on success, 0 on failure */
    public static function invokeArgv(int $priority): int
    {
        return @\proc_nice($priority) ? 1 : 0;
    }
}

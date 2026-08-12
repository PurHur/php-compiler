<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chroot() for compiled JIT/AOT modules (#30558, php-in-PHP).
 *
 * Leaf is `@chroot` → NestedJIT whitelist {@see chroot_} →
 * {@see \PHPCompiler\JIT\Builtin\StringChroot::invokeNestedLeaf} (no kernel).
 * Keep NestedJIT TU small — no {@see VmChrootPure} pull (#579 stubs; chdir #29219 shape).
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (#20603 / HashEquals i32 ABI).
 * Warning on failure stays on the VM {@see chroot_} path (#29360).
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chroot)
 */
final class ChrootJitHelper
{
    /** @return int 1 on success, 0 on failure */
    public static function invokeArgv(string $path): int
    {
        return @\chroot($path) ? 1 : 0;
    }
}

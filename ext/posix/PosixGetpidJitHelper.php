<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_getpid() for compiled JIT/AOT modules (#30696, php-in-PHP).
 *
 * Leaf is `@posix_getpid` → NestedJIT whitelist {@see posix_getpid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixGetpidJit} thin libc getpid(2) leaf via
 * {@see \PHPCompiler\ext\standard\JitGetmypidKernel} (getmypid #30623 shape).
 * Keep NestedJIT TU small — no {@see VmPosix} / {@see VmDate} pull (#579 stubs).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getpid)
 */
final class PosixGetpidJitHelper
{
    public static function getpidArgv(): int
    {
        return (int) @\posix_getpid();
    }
}

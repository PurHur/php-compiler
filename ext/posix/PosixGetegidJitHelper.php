<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_getegid() for compiled JIT/AOT modules (#30986, php-in-PHP).
 *
 * Leaf is `@posix_getegid` → NestedJIT whitelist {@see posix_getegid} →
 * {@see \PHPCompiler\JIT\Builtin\PosixGetegidJit} thin libc getegid(2) leaf via
 * {@see JitPosixGetegidKernel} (posix_getgid #30803 shape).
 * Keep NestedJIT TU small — no {@see VmPosix} pull (#579 stubs).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getegid)
 */
final class PosixGetegidJitHelper
{
    public static function getegidArgv(): int
    {
        return (int) @\posix_getegid();
    }
}

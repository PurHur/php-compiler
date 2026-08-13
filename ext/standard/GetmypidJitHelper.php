<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * getmypid() for compiled JIT/AOT modules (#30623, php-in-PHP).
 *
 * Leaf is `@getmypid` → NestedJIT whitelist {@see getmypid} →
 * {@see \PHPCompiler\JIT\Builtin\ProcessIdentityJit} thin libc getpid(2) leaf
 * (time #30332 / proc_nice #30615 shape).
 * Keep NestedJIT TU small — no {@see VmDate} / {@see VmProcessIdentityNative} pull
 * (#579 stubs; former always-on libc #26944).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getmypid)
 */
final class GetmypidJitHelper
{
    public static function getmypidArgv(): int
    {
        return (int) @\getmypid();
    }
}

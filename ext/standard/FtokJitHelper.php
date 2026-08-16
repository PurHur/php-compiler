<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ftok() for compiled JIT/AOT modules (#31478, php-in-PHP).
 *
 * Leaf is `@ftok` → NestedJIT whitelist {@see ftok} →
 * {@see \PHPCompiler\JIT\Builtin\FtokRuntime} thin libc ftok(3) leaf via
 * {@see JitFtokKernel} (posix_getpid #30696 / getmypid #30623 shape).
 * Keep NestedJIT TU small — no {@see VmFtok} / {@see VmStatNative} pull (#27389 stubs).
 * php-src: ext/standard/ftok.c — PHP_FUNCTION(ftok)
 */
final class FtokJitHelper
{
    public static function ftokArgv(string $path, int $projId): int
    {
        $key = @\ftok($path, \chr($projId & 0xff));

        return false === $key ? -1 : (int) $key;
    }
}

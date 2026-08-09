<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sys_get_temp_dir() for compiled JIT/AOT modules (#29433, #9585, php-in-PHP).
 *
 * Leaf is `@sys_get_temp_dir` → NestedJIT whitelist {@see sys_get_temp_dir} →
 * {@see \PHPCompiler\JIT\Builtin\SysGetTempDirRuntime::invokeNestedLeaf} (thin getenv/realpath
 * libc; no VmSysGetTempDirNative pull in this TU — #26929 NestedJIT segfault root cause).
 * php-src: ext/standard/file.c — PHP_FUNCTION(sys_get_temp_dir)
 */
final class SysGetTempDirJitHelper
{
    public static function resolveJit(): string
    {
        $dir = @\sys_get_temp_dir();

        return \is_string($dir) && '' !== $dir ? $dir : '/tmp';
    }
}

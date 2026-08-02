<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sys_get_temp_dir() for VM / algorithm reference (#9585, php-in-PHP).
 *
 * Thin AOT/JIT emit uses libc getenv/realpath via {@see \PHPCompiler\JIT\Builtin\SysGetTempDirRuntime}
 * (NestedJIT of this helper segfaults under user-script AOT — #26929).
 * SSOT: {@see VmSysGetTempDirNative::resolve()}
 * php-src: ext/standard/file.c — PHP_FUNCTION(sys_get_temp_dir)
 */
final class SysGetTempDirJitHelper
{
    public static function resolve(): string
    {
        return VmSysGetTempDirNative::resolve();
    }
}

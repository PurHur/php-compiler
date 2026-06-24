<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sys_get_temp_dir() for compiled JIT/AOT modules (#9585, php-in-PHP).
 *
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

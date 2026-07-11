<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sys_get_temp_dir() VM — {@see VmSysGetTempDirPure} SSOT, no libc FFI (#8180, #12155).
 *
 * Mirrors {@see SysGetTempDirJitHelper} — TMPDIR/TEMP/TMP then /tmp.
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(sys_get_temp_dir)
 */
final class VmSysGetTempDirNative
{
    public static function available(): bool
    {
        return VmSysGetTempDirPure::available();
    }

    public static function resolve(): string
    {
        return VmSysGetTempDirPure::resolve();
    }
}

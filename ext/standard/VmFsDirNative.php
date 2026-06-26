<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * mkdir/rmdir/chmod/chown/chgrp for VM — {@see VmFsDirPure} SSOT, no libc FFI (#8991, #12317).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\StringFsDirJit} (__compiler_mkdir/chown/chgrp)
 * and {@see JitRmdir}/{@see JitChmod} (php-src ext/standard/filestat.c).
 */
final class VmFsDirNative
{
    public static function available(): bool
    {
        return VmFsDirPure::available();
    }

    public static function mkdir(string $path, int $mode, bool $recursive): bool
    {
        return VmFsDirPure::mkdir($path, $mode, $recursive);
    }

    public static function rmdir(string $path): bool
    {
        return VmFsDirPure::rmdir($path);
    }

    public static function chmod(string $path, int $permissions): bool
    {
        return VmFsDirPure::chmod($path, $permissions);
    }

    public static function chown(string $path, int $uid): bool
    {
        return VmFsDirPure::chown($path, $uid);
    }

    public static function lchown(string $path, int $uid): bool
    {
        return VmFsDirPure::lchown($path, $uid);
    }

    public static function chgrp(string $path, int $gid): bool
    {
        return VmFsDirPure::chgrp($path, $gid);
    }

    public static function lchgrp(string $path, int $gid): bool
    {
        return VmFsDirPure::lchgrp($path, $gid);
    }
}

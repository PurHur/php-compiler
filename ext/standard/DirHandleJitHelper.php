<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * opendir/readdir/closedir/rewinddir for compiled JIT/AOT embed modules (#11811).
 *
 * SSOT: {@see VmDir}
 * php-src: ext/standard/dir.c
 */
final class DirHandleJitHelper
{
    /** @return 0|1 ABI for __compiler_is_dir_resource */
    public static function isDirResourceArgv(int $handle): int
    {
        return VmDir::isValidHandle($handle) ? 1 : 0;
    }

    /** @return int ABI for __compiler_opendir (-1 on failure) */
    public static function opendirArgv(string $path): int
    {
        $handle = VmDir::opendir($path);
        if (false === $handle) {
            return -1;
        }

        return (int) $handle;
    }

    public static function readdirArgv(int $handle): ?string
    {
        $entry = VmDir::readdir($handle);
        if (false === $entry) {
            return null;
        }

        return (string) $entry;
    }

    /** @return 0|1 ABI for __compiler_closedir */
    public static function closedirArgv(int $handle): int
    {
        if (!VmDir::isValidHandle($handle)) {
            return 0;
        }
        VmDir::closedir($handle);

        return 1;
    }

    /** @return 0|1 ABI for __compiler_rewinddir */
    public static function rewinddirArgv(int $handle): int
    {
        if (!VmDir::isValidHandle($handle)) {
            return 0;
        }
        VmDir::rewinddir($handle);

        return 1;
    }
}

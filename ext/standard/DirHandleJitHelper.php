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

    /** @return string|null — handle -1 uses EG(default_directory) (#31450) */
    public static function readdirArgv(int $handle): ?string
    {
        if (-1 === $handle) {
            $handle = VmDirArg::requireDefaultDirHandle();
        }
        $entry = VmDir::readdir($handle);
        if (false === $entry) {
            return null;
        }

        return (string) $entry;
    }

    /** @return 0|1 ABI for __compiler_closedir — handle -1 uses EG(default_directory) (#27999) */
    public static function closedirArgv(int $handle): int
    {
        if (-1 === $handle) {
            $handle = VmDirArg::requireDefaultDirHandle();
        }
        if (!VmDir::isValidHandle($handle)) {
            return 0;
        }
        VmDir::closedir($handle);

        return 1;
    }

    /** @return 0|1 ABI for __compiler_rewinddir — handle -1 uses EG(default_directory) (#31451) */
    public static function rewinddirArgv(int $handle): int
    {
        if (-1 === $handle) {
            $handle = VmDirArg::requireDefaultDirHandle();
        }
        if (!VmDir::isValidHandle($handle)) {
            return 0;
        }
        VmDir::rewinddir($handle);

        return 1;
    }
}

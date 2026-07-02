<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM helpers for opendir/readdir/closedir/rewinddir (ext/standard/dir.c; issue #3235, #5494).
 *
 * php-src: ext/standard/dir.c — php_opendir, php_readdir, php_closedir, php_rewinddir
 */
final class VmDir
{
    /** @return int|false */
    public static function opendir(string $path): int|false
    {
        if (!VmDirNative::available()) {
            return false;
        }

        return VmDirNative::opendir($path);
    }

    /** @return string|false */
    public static function readdir(int $handle): string|false
    {
        if (!VmDirNative::available()) {
            return false;
        }

        return VmDirNative::readdir($handle);
    }

    public static function closedir(int $handle): void
    {
        if (!VmDirNative::available()) {
            return;
        }
        VmDirNative::closedir($handle);
    }

    public static function rewinddir(int $handle): void
    {
        if (!VmDirNative::available()) {
            return;
        }
        VmDirNative::rewinddir($handle);
    }

    public static function isValidHandle(int $handle): bool
    {
        if (!VmDirNative::available()) {
            return false;
        }

        return VmDirNative::isValidHandle($handle);
    }

    /**
     * scandir() for VM — libc scandir(3) via VmDirNative, no host \\scandir() (#5048, php-src dir.c).
     *
     * @return list<string>|false
     */
    public static function scandir(string $path, int $sortingOrder = \SCANDIR_SORT_ASCENDING): array|false
    {
        if (!VmDirNative::available()) {
            return false;
        }
        if (\SCANDIR_SORT_NONE === $sortingOrder) {
            return VmDirNative::listUnsorted($path);
        }

        $names = VmDirNative::listSorted($path);
        if (false === $names) {
            return false;
        }
        if (\SCANDIR_SORT_DESCENDING === $sortingOrder) {
            $names = \array_reverse($names, false);
        }

        return $names;
    }
}

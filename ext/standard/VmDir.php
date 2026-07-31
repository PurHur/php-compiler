<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM helpers for opendir/readdir/closedir/rewinddir (ext/standard/dir.c; issue #3235, #5494).
 *
 * php-src: ext/standard/dir.c — php_opendir, php_readdir, php_closedir, php_rewinddir
 * User wrappers: main/streams/userspace.c dir_* via {@see VmUserStream} (#26002).
 */
final class VmDir
{
    /** @return int|false */
    public static function opendir(string $path): int|false
    {
        $user = VmUserStream::tryOpendir($path);
        if (null !== $user) {
            return $user;
        }
        if (!VmDirNative::available()) {
            return false;
        }

        return VmDirNative::opendir($path);
    }

    /** @return string|false */
    public static function readdir(int $handle): string|false
    {
        if (VmUserStream::isValidDirHandle($handle)) {
            return VmUserStream::dirReaddir($handle);
        }
        if (!VmDirNative::available()) {
            return false;
        }

        return VmDirNative::readdir($handle);
    }

    public static function closedir(int $handle): void
    {
        if (VmUserStream::isValidDirHandle($handle)) {
            VmUserStream::dirClosedir($handle);

            return;
        }
        if (!VmDirNative::available()) {
            return;
        }
        VmDirNative::closedir($handle);
    }

    public static function rewinddir(int $handle): void
    {
        if (VmUserStream::isValidDirHandle($handle)) {
            VmUserStream::dirRewinddir($handle);

            return;
        }
        if (!VmDirNative::available()) {
            return;
        }
        VmDirNative::rewinddir($handle);
    }

    public static function isValidHandle(int $handle): bool
    {
        if (VmUserStream::isValidDirHandle($handle)) {
            return true;
        }
        if (!VmDirNative::available()) {
            return false;
        }

        return VmDirNative::isValidHandle($handle);
    }

    /**
     * scandir() for VM — libc scandir(3) via VmDirNative, no host \\scandir() (#5048, php-src dir.c).
     * Custom protocols: userspace dir_* (#26002).
     *
     * @return list<string>|false
     */
    public static function scandir(string $path, int $sortingOrder = \SCANDIR_SORT_ASCENDING): array|false
    {
        $user = VmUserStream::tryScandir($path, $sortingOrder);
        if (null !== $user) {
            return $user;
        }
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

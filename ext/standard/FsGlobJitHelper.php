<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * glob()/scandir() for compiled JIT/AOT modules (#11515, #12909, php-in-PHP).
 *
 * SSOT: {@see VmFsGlob::glob()}, {@see VmDir::scandir()}, {@see VmFs::stringListToArray()}
 * php-src: ext/standard/dir.c — PHP_FUNCTION(glob), PHP_FUNCTION(scandir)
 */
final class FsGlobJitHelper
{
    /** @return HashTable|null null when glob() fails (Zend false) */
    public static function globArgv(string $pattern, int $flags): ?HashTable
    {
        $result = VmFsGlob::glob($pattern, $flags);
        if (false === $result) {
            return null;
        }

        return VmFs::stringListToArray($result);
    }

    /** @return HashTable|null null when scandir() fails (Zend false) */
    public static function scandirArgv(string $path, int $sortOrder): ?HashTable
    {
        $result = VmDir::scandir($path, $sortOrder);
        if (false === $result) {
            return null;
        }

        return VmFs::stringListToArray($result);
    }
}

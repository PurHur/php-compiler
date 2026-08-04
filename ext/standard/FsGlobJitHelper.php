<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * glob()/scandir() for compiled JIT/AOT modules (#11515, #12909, #27235, #27236, php-in-PHP).
 *
 * Return type is `?array` (not {@see HashTable}): NestedJIT maps class HashTable to object
 * ABI and TypeErrors / null under thin AOT (#20652 peer HashAlgosJitHelper; #27235/#27236).
 * `array` → `__hashtable__*`.
 *
 * SSOT: {@see VmFsGlob::glob()}, {@see VmDir::scandir()}
 * php-src: ext/standard/dir.c — PHP_FUNCTION(glob), PHP_FUNCTION(scandir)
 */
final class FsGlobJitHelper
{
    /**
     * @return list<string>|null null when glob() fails (Zend false)
     */
    public static function globArgv(string $pattern, int $flags): ?array
    {
        $result = VmFsGlob::glob($pattern, $flags);
        if (!\is_array($result)) {
            return null;
        }

        return array_values($result);
    }

    /**
     * @return list<string>|null null when scandir() fails (Zend false)
     */
    public static function scandirArgv(string $path, int $sortOrder): ?array
    {
        $result = VmDir::scandir($path, $sortOrder);
        if (!\is_array($result)) {
            ScandirFailureJitHelper::emitWarnings($path);

            return null;
        }

        return array_values($result);
    }
}

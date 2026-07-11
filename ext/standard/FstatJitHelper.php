<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * fstat() JIT/AOT helper — SSOT {@see VmFs::fstat()} / {@see VmStreamFstat} (#10460).
 *
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(fstat)
 */
final class FstatJitHelper
{
    /** @return HashTable|null null when fstat fails */
    public static function fstatArgv(int $handle): ?HashTable
    {
        $info = VmFs::fstat($handle);
        if (false === $info) {
            return null;
        }

        return $info;
    }
}

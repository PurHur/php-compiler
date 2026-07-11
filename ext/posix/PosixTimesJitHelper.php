<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\VM\HashTable;

/**
 * posix_times() for compiled JIT/AOT modules (#9218, php-in-PHP).
 *
 * SSOT: {@see VmPosix::times}.
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_times)
 */
final class PosixTimesJitHelper
{
    public static function resolve(): ?HashTable
    {
        try {
            return VmPosix::timesToHashTable(VmPosix::times());
        } catch (\Throwable) {
            return null;
        }
    }
}

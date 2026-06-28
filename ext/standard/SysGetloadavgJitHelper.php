<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * sys_getloadavg() for compiled JIT/AOT modules (#12106, php-in-PHP).
 *
 * SSOT: {@see VmSys::getLoadavg} → {@see VmSysGetloadavgNative} (libc FFI or {@see VmSysGetloadavgPure}).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sys_getloadavg)
 */
final class SysGetloadavgJitHelper
{
    public static function resolve(): ?HashTable
    {
        $avg = VmSys::getLoadavg();

        return false === $avg ? null : VmSys::loadavgToHashTable($avg);
    }
}

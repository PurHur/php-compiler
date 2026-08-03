<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * sys_getloadavg() for compiled JIT/AOT modules (#12106, #27294, php-in-PHP).
 *
 * NestedJIT must not return {@see HashTable} under thin AOT — that path pointer-casts a
 * miscompiled object to `__hashtable__*` and segfaults on is_array/count (#27294; peer
 * gc_status #26943 / cal_info #27354). Expose scalars; the LLVM bridge materializes the HT.
 *
 * SSOT: {@see VmSys::getLoadavg} → {@see VmSysGetloadavgNative} → {@see VmSysGetloadavgPure}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sys_getloadavg)
 */
final class SysGetloadavgJitHelper
{
    /** @var array{0: float, 1: float, 2: float}|null */
    private static ?array $last = null;

    /** Host / unit tests — build a real VM HashTable (not NestedJIT under thin AOT). */
    public static function resolve(): ?HashTable
    {
        $avg = VmSys::getLoadavg();

        return false === $avg ? null : VmSys::loadavgToHashTable($avg);
    }

    /** NestedJIT-safe: 1 when three loads are available, else 0. */
    public static function resolveOk(): int
    {
        $avg = VmSys::getLoadavg();
        if (false === $avg) {
            self::$last = null;

            return 0;
        }
        self::$last = $avg;

        return 1;
    }

    public static function loadAt(int $index): float
    {
        if (null === self::$last || $index < 0 || $index > 2) {
            return 0.0;
        }

        return self::$last[$index];
    }

    /** @internal */
    public static function resetForTest(): void
    {
        self::$last = null;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\MemoryAccounting;

/**
 * memory_get_*() / gc_mem_caches() for compiled JIT/AOT modules (#9377, php-in-PHP).
 *
 * SSOT: {@see VmMemory}, {@see MemoryAccounting}, {@see VmGcStatus}
 * php-src: ext/standard/basic_functions.c, ext/standard/php_gc.c
 */
final class MemoryJitHelper
{
    public static function getUsage(bool $realUsage): int
    {
        if ($realUsage) {
            return VmMemory::getUsage(true);
        }

        return MemoryAccounting::usageAfterPeakQuery();
    }

    public static function getPeakUsage(bool $realUsage): int
    {
        if ($realUsage) {
            return VmMemory::getPeakUsage(true);
        }
        MemoryAccounting::syncPeakFromCurrent();

        return MemoryAccounting::markPeakQuery(MemoryAccounting::peakBytes());
    }

    /** php-src zend_memory_reset_peak_usage — zero args (#26104). */
    public static function resetPeakUsage(): void
    {
        VmMemory::resetPeakUsage();
    }

    public static function noteAlloc(int $delta): void
    {
        MemoryAccounting::noteBytes($delta);
    }

    public static function gcMemCaches(): int
    {
        return VmGcStatus::memCaches();
    }
}

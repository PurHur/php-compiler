<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\CycleCollector;
use PHPCompiler\VM\HashTable;

/**
 * gc_status() / gc_mem_caches() VM helpers (ext/standard / Zend gc_status parity, #3280, #20627).
 */
final class VmGcStatus
{
    public static function statusTable(Context $ctx): HashTable
    {
        if (CompilerVersion::supportsGcStatusPhp84Schema()) {
            $s = CycleCollector::status($ctx);

            return GcStatusJitHelper::buildTable(
                $s['running'],
                $s['protected'],
                $s['full'],
                $s['runs'],
                $s['collected'],
                $s['threshold'],
                $s['buffer_size'],
                $s['roots'],
                $s['application_time'],
                $s['collector_time'],
                $s['destructor_time'],
                $s['free_time']
            );
        }

        $legacy = CycleCollector::legacyStatus($ctx);

        return GcStatusJitHelper::buildLegacyTable(
            $legacy['runs'],
            $legacy['collected'],
            $legacy['threshold'],
            $legacy['roots']
        );
    }

    public static function memCaches(): int
    {
        return CycleCollector::memCaches();
    }
}

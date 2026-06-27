<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\CycleCollector;
use PHPCompiler\VM\HashTable;

/**
 * gc_status() / gc_mem_caches() VM helpers (ext/standard/php_gc.c parity, #3280).
 */
final class VmGcStatus
{
    public static function statusTable(Context $ctx): HashTable
    {
        $s = CycleCollector::status($ctx);

        return GcStatusJitHelper::buildTable(
            $s['running'],
            $s['protected'],
            $s['full'],
            $s['buffer_size']
        );
    }

    public static function memCaches(): int
    {
        return CycleCollector::memCaches();
    }
}

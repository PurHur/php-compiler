<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\CycleCollector;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * gc_status() / gc_mem_caches() VM helpers (ext/standard/php_gc.c parity, #3280).
 */
final class VmGcStatus
{
    public static function statusTable(Context $ctx): HashTable
    {
        $ht = new HashTable();
        foreach (CycleCollector::status($ctx) as $key => $value) {
            $slot = new Variable();
            if (\is_bool($value)) {
                $slot->bool($value);
            } else {
                $slot->int((int) $value);
            }
            $ht->add((string) $key, $slot);
        }

        return $ht;
    }

    public static function memCaches(): int
    {
        return CycleCollector::memCaches();
    }
}

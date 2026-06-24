<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * gc_status() return table for compiled JIT/AOT modules (#9150, php-in-PHP).
 *
 * VM SSOT delegates via {@see VmGcStatus}; JIT reads LLVM globals in
 * {@see \PHPCompiler\JIT\Builtin\GcStatusRuntime} and passes snapshots here.
 * php-src: ext/standard/php_gc.c — PHP_FUNCTION(gc_status)
 */
final class GcStatusJitHelper
{
    public static function buildTable(int $runs, int $collected, int $threshold, int $roots): HashTable
    {
        $ht = new HashTable();
        self::addInt($ht, 'runs', $runs);
        self::addInt($ht, 'collected', $collected);
        self::addInt($ht, 'threshold', $threshold);
        self::addInt($ht, 'roots', $roots);

        return $ht;
    }

    private static function addInt(HashTable $ht, string $key, int $value): void
    {
        $slot = new Variable();
        $slot->int($value);
        $ht->add($key, $slot);
    }
}

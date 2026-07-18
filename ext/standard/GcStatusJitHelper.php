<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * gc_status() return table for compiled JIT/AOT modules (#9150, php-in-PHP).
 *
 * VM SSOT delegates via {@see VmGcStatus}; JIT reads LLVM globals in
 * {@see \PHPCompiler\JIT\Builtin\GcStatusRuntime} and passes snapshots here.
 * php-src: Zend/zend_builtin_functions.c — ZEND_FUNCTION(gc_status)
 * (#20627: PHP 8.3+ retains legacy counters + timing floats alongside running/…).
 */
final class GcStatusJitHelper
{
    /**
     * PHP 8.3+/8.4 gc_status() table — 12 keys matching php-src order.
     *
     * @see https://github.com/php/php-src/blob/master/Zend/zend_builtin_functions.c ZEND_FUNCTION(gc_status)
     */
    public static function buildTable(
        bool $running,
        bool $protected,
        bool $full,
        int $runs,
        int $collected,
        int $threshold,
        int $bufferSize,
        int $roots,
        float $applicationTime,
        float $collectorTime,
        float $destructorTime,
        float $freeTime
    ): HashTable {
        if (!CompilerVersion::supportsGcStatusPhp84Schema()) {
            throw new \LogicException('buildTable() requires PHP 8.4+ gc_status schema (#12790, #20627)');
        }

        $ht = new HashTable();
        self::addBool($ht, 'running', $running);
        self::addBool($ht, 'protected', $protected);
        self::addBool($ht, 'full', $full);
        self::addInt($ht, 'runs', $runs);
        self::addInt($ht, 'collected', $collected);
        self::addInt($ht, 'threshold', $threshold);
        self::addInt($ht, 'buffer_size', $bufferSize);
        self::addInt($ht, 'roots', $roots);
        self::addFloat($ht, 'application_time', $applicationTime);
        self::addFloat($ht, 'collector_time', $collectorTime);
        self::addFloat($ht, 'destructor_time', $destructorTime);
        self::addFloat($ht, 'free_time', $freeTime);

        return $ht;
    }

    /** php-src php_gc.c / zend_builtin_functions.c — pre-8.3 gc_status() keys (#12790). */
    public static function buildLegacyTable(int $runs, int $collected, int $threshold, int $roots): HashTable
    {
        $ht = new HashTable();
        self::addInt($ht, 'runs', $runs);
        self::addInt($ht, 'collected', $collected);
        self::addInt($ht, 'threshold', $threshold);
        self::addInt($ht, 'roots', $roots);

        return $ht;
    }

    private static function addBool(HashTable $ht, string $key, bool $value): void
    {
        $slot = new Variable();
        $slot->bool($value);
        $ht->add($key, $slot);
    }

    private static function addInt(HashTable $ht, string $key, int $value): void
    {
        $slot = new Variable();
        $slot->int($value);
        $ht->add($key, $slot);
    }

    private static function addFloat(HashTable $ht, string $key, float $value): void
    {
        $slot = new Variable();
        $slot->float($value);
        $ht->add($key, $slot);
    }
}

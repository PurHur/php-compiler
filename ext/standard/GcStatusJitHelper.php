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
 * php-src: ext/standard/php_gc.c — PHP_FUNCTION(gc_status) (PHP 8.4 schema, #12780)
 */
final class GcStatusJitHelper
{
    public static function buildTable(bool $running, bool $protected, bool $full, int $bufferSize): HashTable
    {
        $ht = new HashTable();
        self::addBool($ht, 'running', $running);
        self::addBool($ht, 'protected', $protected);
        self::addBool($ht, 'full', $full);
        self::addInt($ht, 'buffer_size', $bufferSize);

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
}

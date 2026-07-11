<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Tracks live VM array HashTables for {@see CycleCollector} (#13400).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_gc.c gc_root_buffer
 */
final class HashTableRegistry
{
    /** @var array<int, HashTable> spl_object_id => table */
    private static array $instances = [];

    public static function register(HashTable $table): void
    {
        self::$instances[\spl_object_id($table)] = $table;
    }

    public static function unregister(int $id): void
    {
        unset(self::$instances[$id]);
    }

    public static function isRegistered(int $id): bool
    {
        return isset(self::$instances[$id]);
    }

    /** @return array<int, HashTable> */
    public static function snapshot(): array
    {
        return self::$instances;
    }

    public static function release(HashTable $table): void
    {
        $id = \spl_object_id($table);
        if (!isset(self::$instances[$id])) {
            return;
        }
        $table->destroyForGc();
        self::unregister($id);
    }

    public static function reset(): void
    {
        self::$instances = [];
    }
}

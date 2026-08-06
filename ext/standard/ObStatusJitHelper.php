<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * ob_get_status() status-row builder for VM + compiled JIT/AOT modules (#9497, #28153).
 *
 * Key insertion order matches php-src ext/standard/output.c — `name` first, then type/flags/….
 * VM orchestration: {@see VmOb}. JIT bridge: {@see \PHPCompiler\JIT\Builtin\ObStatusRuntime}.
 */
final class ObStatusJitHelper
{
    public const HANDLER_NAME = 'default output handler';

    /** PHP_OUTPUT_HANDLER_INTERNAL */
    public const HANDLER_TYPE = 0;

    /** PHP_OUTPUT_HANDLER_CLEANABLE|FLUSHABLE|REMOVABLE */
    public const HANDLER_FLAGS = 112;

    public const DEFAULT_BUFFER_SIZE = 16384;

    /**
     * Full status row (php-src key order). Used by VM with the real handler display name.
     */
    public static function buildStatusEntry(int $level, int $bufferUsed, string $handlerName): HashTable
    {
        $ht = new HashTable();
        $name = new Variable();
        $name->string($handlerName);
        $ht->add('name', $name);
        self::addInt($ht, 'type', self::HANDLER_TYPE);
        self::addInt($ht, 'flags', self::HANDLER_FLAGS);
        self::addInt($ht, 'level', $level);
        self::addInt($ht, 'chunk_size', 0);
        self::addInt($ht, 'buffer_size', self::DEFAULT_BUFFER_SIZE);
        self::addInt($ht, 'buffer_used', $bufferUsed);

        return $ht;
    }

    /**
     * JIT/AOT compiled helper — default handler name baked in (no string arg ABI).
     *
     * @see buildStatusEntry
     */
    public static function buildStatusEntryDefault(int $level, int $bufferUsed): HashTable
    {
        return self::buildStatusEntry($level, $bufferUsed, self::HANDLER_NAME);
    }

    /**
     * @deprecated Prefer {@see buildStatusEntryDefault()} — kept as alias for call-site churn.
     */
    public static function buildStatusEntryPartial(int $level, int $bufferUsed): HashTable
    {
        return self::buildStatusEntryDefault($level, $bufferUsed);
    }

    private static function addInt(HashTable $ht, string $key, int $value): void
    {
        $slot = new Variable();
        $slot->int($value);
        $ht->add($key, $slot);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * ob_get_status() numeric fields for compiled JIT/AOT modules (#9497, php-in-PHP).
 *
 * Handler name string is attached in {@see \PHPCompiler\JIT\Builtin\ObStatusRuntime} bridge.
 * VM orchestration: {@see VmOb}.
 * php-src: ext/standard/output.c — PHP_FUNCTION(ob_get_status)
 */
final class ObStatusJitHelper
{
    public const HANDLER_NAME = 'default output handler';

    /** PHP_OUTPUT_HANDLER_INTERNAL */
    public const HANDLER_TYPE = 0;

    /** PHP_OUTPUT_HANDLER_CLEANABLE|FLUSHABLE|REMOVABLE */
    public const HANDLER_FLAGS = 112;

    public const DEFAULT_BUFFER_SIZE = 16384;

    /** Numeric/status fields only — compiled into JIT/AOT (no Variable::string in this file). */
    public static function buildStatusEntryPartial(int $level, int $bufferUsed): HashTable
    {
        $ht = new HashTable();
        self::addInt($ht, 'type', self::HANDLER_TYPE);
        self::addInt($ht, 'flags', self::HANDLER_FLAGS);
        self::addInt($ht, 'level', $level);
        self::addInt($ht, 'chunk_size', 0);
        self::addInt($ht, 'buffer_size', self::DEFAULT_BUFFER_SIZE);
        self::addInt($ht, 'buffer_used', $bufferUsed);

        return $ht;
    }

    private static function addInt(HashTable $ht, string $key, int $value): void
    {
        $slot = new Variable();
        $slot->int($value);
        $ht->add($key, $slot);
    }
}

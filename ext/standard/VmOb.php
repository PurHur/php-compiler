<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\Variable;

/**
 * Output-buffer helpers (ext/standard/output.c parity, issues #3236, #3647).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/output.c PHP_FUNCTION(ob_get_status)
 */
final class VmOb
{
    public const HANDLER_NAME = 'default output handler';

    /** PHP_OUTPUT_HANDLER_INTERNAL */
    private const HANDLER_TYPE = 0;

    /** PHP_OUTPUT_HANDLER_CLEANABLE|FLUSHABLE|REMOVABLE */
    private const HANDLER_FLAGS = 112;

    private const DEFAULT_BUFFER_SIZE = 16384;

    /** ob_list_handlers() — handler name per buffer level (ext/standard/output.c, #3588). */
    public static function listHandlers(): HashTable
    {
        $names = [];
        foreach (OutputBuffer::getHandlerNames() as $handler) {
            $names[] = null !== $handler ? $handler : self::HANDLER_NAME;
        }

        return VmFs::stringListToArray($names);
    }

    public static function getStatus(bool $full): HashTable
    {
        $buffers = OutputBuffer::getBuffers();
        if ([] === $buffers) {
            return new HashTable();
        }
        $handlerNames = OutputBuffer::getHandlerNames();
        if (!$full) {
            $idx = \count($buffers) - 1;

            return self::bufferStatusToHashTable(
                $idx,
                $buffers[$idx],
                $handlerNames[$idx] ?? null
            );
        }
        $list = new HashTable();
        foreach ($buffers as $idx => $contents) {
            $entry = new Variable();
            $entry->array(self::bufferStatusToHashTable(
                $idx,
                $contents,
                $handlerNames[$idx] ?? null
            ));
            $list->append($entry);
        }

        return $list;
    }

    private static function bufferStatusToHashTable(int $level, string $contents, ?string $handlerName = null): HashTable
    {
        $used = \strlen($contents);
        $ht = new HashTable();
        self::addString($ht, 'name', null !== $handlerName ? $handlerName : self::HANDLER_NAME);
        self::addInt($ht, 'type', self::HANDLER_TYPE);
        self::addInt($ht, 'flags', self::HANDLER_FLAGS);
        self::addInt($ht, 'level', $level);
        self::addInt($ht, 'chunk_size', 0);
        self::addInt($ht, 'buffer_size', self::DEFAULT_BUFFER_SIZE);
        self::addInt($ht, 'buffer_used', $used);

        return $ht;
    }

    private static function addString(HashTable $ht, string $key, string $value): void
    {
        $slot = new Variable();
        $slot->string($value);
        $ht->add($key, $slot);
    }

    private static function addInt(HashTable $ht, string $key, int $value): void
    {
        $slot = new Variable();
        $slot->int($value);
        $ht->add($key, $slot);
    }
}

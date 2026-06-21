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
    public const HANDLER_NAME = ObStatusJitHelper::HANDLER_NAME;

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

            return self::buildStatusEntry(
                $idx,
                \strlen($buffers[$idx]),
                $handlerNames[$idx] ?? null
            );
        }
        $list = new HashTable();
        foreach ($buffers as $idx => $contents) {
            $entry = new Variable();
            $entry->array(self::buildStatusEntry(
                $idx,
                \strlen($contents),
                $handlerNames[$idx] ?? null
            ));
            $list->append($entry);
        }

        return $list;
    }

    private static function buildStatusEntry(int $level, int $bufferUsed, ?string $handlerName = null): HashTable
    {
        $ht = ObStatusJitHelper::buildStatusEntryPartial($level, $bufferUsed);
        $slot = new Variable();
        $slot->string(null !== $handlerName ? $handlerName : self::HANDLER_NAME);
        $ht->add('name', $slot);

        return $ht;
    }
}

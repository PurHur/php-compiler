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
        $ctx = OutputBuffer::getActiveContext();
        $names = [];
        foreach (OutputBuffer::getHandlers() as $handler) {
            $names[] = VmObOutput::handlerDisplayName($handler, $ctx);
        }

        return VmFs::stringListToArray($names);
    }

    public static function getStatus(bool $full): HashTable
    {
        $buffers = OutputBuffer::getBuffers();
        if ([] === $buffers) {
            return new HashTable();
        }
        $handlers = OutputBuffer::getHandlers();
        $ctx = OutputBuffer::getActiveContext();
        if (!$full) {
            $idx = \count($buffers) - 1;

            return self::buildStatusEntry(
                $idx,
                \strlen($buffers[$idx]),
                VmObOutput::handlerDisplayName($handlers[$idx] ?? null, $ctx)
            );
        }
        $list = new HashTable();
        foreach ($buffers as $idx => $contents) {
            $entry = new Variable();
            $entry->array(self::buildStatusEntry(
                $idx,
                \strlen($contents),
                VmObOutput::handlerDisplayName($handlers[$idx] ?? null, $ctx)
            ));
            $list->append($entry);
        }

        return $list;
    }

    private static function buildStatusEntry(int $level, int $bufferUsed, string $handlerName): HashTable
    {
        return ObStatusJitHelper::buildStatusEntry($level, $bufferUsed, $handlerName);
    }
}

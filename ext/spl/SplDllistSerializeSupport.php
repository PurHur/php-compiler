<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * Zend serialize wire for SplDoublyLinkedList / SplStack / SplQueue (php-src ext/spl/spl_dllist.c; #14164).
 */
final class SplDllistSerializeSupport
{
    public const CLASS_DLLIST = 'spldoublylinkedlist';
    public const CLASS_STACK = 'splstack';
    public const CLASS_QUEUE = 'splqueue';

    public static function isSplDllistClass(string $lcClass): bool
    {
        return self::CLASS_DLLIST === $lcClass
            || self::CLASS_STACK === $lcClass
            || self::CLASS_QUEUE === $lcClass;
    }

    public static function encodeZendSerializeWire(ObjectEntry $entry): string
    {
        return VmSerialize::encodeIntegerKeyedPropertyBag($entry->class->name, [
            0 => SplDoublyLinkedListBuiltin::getIteratorMode($entry),
            1 => SplDoublyLinkedListBuiltin::exportElements($entry),
            2 => [],
        ]);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function restoreFromZendSerialize(
        Context $ctx,
        string $lcClass,
        array $data
    ): ?ObjectEntry {
        if (!self::isSplDllistClass($lcClass) || !isset($ctx->classes[$lcClass])) {
            return null;
        }
        if (!isset($data[1]) || !\is_array($data[1])) {
            return null;
        }
        $mode = isset($data[0]) ? (int) $data[0] : 0;
        $entry = new ObjectEntry($ctx->classes[$lcClass]);
        $entry->constructed = true;
        SplDoublyLinkedListBuiltin::restoreFromExported($entry, $mode, $data[1]);

        return $entry;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * Zend serialize wire for SPL ArrayObject / ArrayIterator (php-src ext/spl/spl_array.c; #10711).
 */
final class SplArraySerializeSupport
{
    public const CLASS_ARRAYOBJECT = 'arrayobject';
    public const CLASS_ARRAYITERATOR = 'arrayiterator';

    public static function isSplArrayClass(string $lcClass): bool
    {
        return self::CLASS_ARRAYOBJECT === $lcClass || self::CLASS_ARRAYITERATOR === $lcClass;
    }

    public static function encodeZendSerializeWire(ObjectEntry $entry): string
    {
        if (!SplArrayStorage::hasState($entry)) {
            return VmSerialize::encodeIntegerKeyedPropertyBag($entry->class->name, [
                0 => 0,
                1 => [],
                2 => [],
                3 => null,
            ]);
        }
        $state = SplArrayStorage::state($entry);

        return VmSerialize::encodeIntegerKeyedPropertyBag($entry->class->name, [
            0 => $state['flags'],
            1 => SplArrayStorage::hashTableToExportedArray($state['table']),
            2 => $state['propList'],
            3 => $state['iteratorClass'],
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
        if (!self::isSplArrayClass($lcClass) || !isset($ctx->classes[$lcClass])) {
            return null;
        }
        if (!isset($data[1]) || !\is_array($data[1])) {
            return null;
        }
        $flags = isset($data[0]) ? (int) $data[0] : 0;
        $propList = isset($data[2]) && \is_array($data[2]) ? $data[2] : [];
        $iteratorClass = $data[3] ?? null;
        if (null !== $iteratorClass && !\is_string($iteratorClass)) {
            $iteratorClass = null;
        }
        $entry = new ObjectEntry($ctx->classes[$lcClass]);
        $entry->constructed = true;
        SplArrayStorage::restoreFromExported(
            $ctx,
            $entry,
            $flags,
            $data[1],
            $propList,
            $iteratorClass
        );

        return $entry;
    }
}

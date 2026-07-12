<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** Object-id keyed DOM node state (issue #6140). */
final class DomRegistry
{
    private const GLOBAL_KEY = '__phpc_dom_registry';

    /** @return array{states: array<int, DomNodeState>, entries: array<int, ObjectEntry>} */
    private static function &bucket(): array
    {
        if (!isset($GLOBALS[self::GLOBAL_KEY]) || !\is_array($GLOBALS[self::GLOBAL_KEY])) {
            $GLOBALS[self::GLOBAL_KEY] = [
                'states' => [],
                'entries' => [],
            ];
        }

        return $GLOBALS[self::GLOBAL_KEY];
    }

    public static function reset(): void
    {
        $GLOBALS[self::GLOBAL_KEY] = [
            'states' => [],
            'entries' => [],
        ];
    }

    public static function attach(ObjectEntry $entry, DomNodeState $state): void
    {
        $bucket = &self::bucket();
        $bucket['states'][$entry->id] = $state;
        $bucket['entries'][$entry->id] = $entry;
        VmDom::initRegistryIdProperty($entry);
    }

    public static function entry(int $id): ?ObjectEntry
    {
        $bucket = self::bucket();

        return $bucket['entries'][$id] ?? null;
    }

    public static function state(ObjectEntry $entry): DomNodeState
    {
        $bucket = self::bucket();
        $state = $bucket['states'][$entry->id] ?? null;
        if (null === $state) {
            throw new \LogicException('DOM object has no registered node state in this compiler build');
        }

        return $state;
    }

    public static function has(ObjectEntry $entry): bool
    {
        $bucket = self::bucket();

        return isset($bucket['states'][$entry->id]);
    }
}

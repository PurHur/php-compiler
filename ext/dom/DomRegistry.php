<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** Object-id keyed DOM node state (issue #6140). */
final class DomRegistry
{
    /** @var array<int, DomNodeState> */
    private static array $states = [];

    public static function reset(): void
    {
        self::$states = [];
    }

    public static function attach(ObjectEntry $entry, DomNodeState $state): void
    {
        self::$states[$entry->id] = $state;
    }

    public static function state(ObjectEntry $entry): DomNodeState
    {
        $state = self::$states[$entry->id] ?? null;
        if (null === $state) {
            throw new \LogicException('DOM object has no registered node state in this compiler build');
        }

        return $state;
    }

    public static function has(ObjectEntry $entry): bool
    {
        return isset(self::$states[$entry->id]);
    }
}

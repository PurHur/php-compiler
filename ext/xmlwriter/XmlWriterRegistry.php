<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\VM\ObjectEntry;

/** Object-id keyed XMLWriter streaming state (#6065). */
final class XmlWriterRegistry
{
    /** @var array<int, XmlWriterState> */
    private static array $states = [];

    public static function reset(): void
    {
        self::$states = [];
    }

    public static function attach(ObjectEntry $entry, XmlWriterState $state): void
    {
        self::$states[$entry->id] = $state;
    }

    public static function state(ObjectEntry $entry): XmlWriterState
    {
        $state = self::$states[$entry->id] ?? null;
        if (null === $state) {
            throw new \LogicException('XMLWriter has no registered writer state in this compiler build');
        }

        return $state;
    }

    public static function has(ObjectEntry $entry): bool
    {
        return isset(self::$states[$entry->id]);
    }

    public static function detach(ObjectEntry $entry): void
    {
        unset(self::$states[$entry->id]);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\VM\ObjectEntry;

/** Object-id keyed XMLReader pull-parser state (#6135). */
final class XmlReaderRegistry
{
    /** @var array<int, XmlReaderState> */
    private static array $states = [];

    public static function reset(): void
    {
        self::$states = [];
    }

    public static function attach(ObjectEntry $entry, XmlReaderState $state): void
    {
        self::$states[$entry->id] = $state;
    }

    public static function state(ObjectEntry $entry): XmlReaderState
    {
        $state = self::$states[$entry->id] ?? null;
        if (null === $state) {
            throw new \LogicException('XMLReader has no registered parser state in this compiler build');
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

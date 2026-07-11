<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\VM\ObjectEntry;

/** Object-id keyed SimpleXML node state (issue #3338). */
final class SimpleXmlRegistry
{
    /** @var array<int, SimpleXmlNodeState> */
    private static array $states = [];

    /** @var array<int, list<SimpleXmlNodeState>> */
    private static array $views = [];

    public static function reset(): void
    {
        self::$states = [];
        self::$views = [];
    }

    public static function attach(ObjectEntry $entry, SimpleXmlNodeState $state): void
    {
        self::$states[$entry->id] = $state;
    }

    public static function attachView(ObjectEntry $entry, array $elements): void
    {
        self::$views[$entry->id] = $elements;
    }

    public static function state(ObjectEntry $entry): SimpleXmlNodeState
    {
        $state = self::$states[$entry->id] ?? null;
        if (null === $state) {
            throw new \LogicException('SimpleXMLElement has no registered node state in this compiler build');
        }

        return $state;
    }

    public static function view(ObjectEntry $entry): array
    {
        return self::$views[$entry->id] ?? [self::state($entry)];
    }

    public static function has(ObjectEntry $entry): bool
    {
        return isset(self::$states[$entry->id]) || isset(self::$views[$entry->id]);
    }

    public static function isView(ObjectEntry $entry): bool
    {
        return isset(self::$views[$entry->id]);
    }
}

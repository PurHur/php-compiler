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

    /** @var array<int, true> */
    private static array $attributeViews = [];

    /** @var array<int, int> */
    private static array $documentKeys = [];

    /** @var array<int, array<string, string>> */
    private static array $xpathNamespaces = [];

    public static function reset(): void
    {
        self::$states = [];
        self::$views = [];
        self::$attributeViews = [];
        self::$documentKeys = [];
        self::$xpathNamespaces = [];
    }

    public static function attach(ObjectEntry $entry, SimpleXmlNodeState $state, ?int $documentKey = null): void
    {
        self::$states[$entry->id] = $state;
        if (null !== $documentKey) {
            self::$documentKeys[$entry->id] = $documentKey;
        }
    }

    public static function attachView(ObjectEntry $entry, array $elements, ?int $documentKey = null): void
    {
        self::$views[$entry->id] = $elements;
        if (null !== $documentKey) {
            self::$documentKeys[$entry->id] = $documentKey;
        }
    }

    public static function attachAttributesView(ObjectEntry $entry, SimpleXmlNodeState $state, ?int $documentKey = null): void
    {
        self::attach($entry, $state, $documentKey);
        self::$attributeViews[$entry->id] = true;
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

    public static function isAttributesView(ObjectEntry $entry): bool
    {
        return isset(self::$attributeViews[$entry->id]);
    }

    public static function documentKey(ObjectEntry $entry): int
    {
        return self::$documentKeys[$entry->id] ?? $entry->id;
    }

    /** Document root node for a loaded tree (object id of the root SimpleXMLElement). */
    public static function rootState(int $documentKey): SimpleXmlNodeState
    {
        $state = self::$states[$documentKey] ?? null;
        if (null === $state) {
            throw new \LogicException('SimpleXML document root not found for this compiler build');
        }

        return $state;
    }

    /** @return array<string, string> */
    public static function xpathNamespaces(ObjectEntry $entry): array
    {
        $key = self::documentKey($entry);

        return self::$xpathNamespaces[$key] ?? [];
    }

    public static function registerXPathNamespace(ObjectEntry $entry, string $prefix, string $namespaceUri): bool
    {
        $key = self::documentKey($entry);
        self::$xpathNamespaces[$key][$prefix] = $namespaceUri;

        return true;
    }
}

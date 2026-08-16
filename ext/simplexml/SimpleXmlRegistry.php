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

    /**
     * Live attribute dimension/property handles (`$sxe['a']`, `$attrs->a`).
     * Entry shares the owning element node; name selects `$owner->attributes[$name]`
     * on each read (php-src sxe.c; #22654).
     *
     * @var array<int, true>
     */
    private static array $attributeNodeViews = [];

    /** @var array<int, string> */
    private static array $attributeNodeNames = [];

    /**
     * Namespace filter for live attributes() views (php-src sxe.c; #20332).
     * null/`''` ns ⇒ unqualified attrs only; non-empty ⇒ URI/prefix filter.
     *
     * @var array<int, array{ns: ?string, isPrefix: bool}>
     */
    private static array $attributeViewFilters = [];

    /**
     * Live children() views hold the parent element node; length/iteration re-read
     * `$parent->children` (php-src sxe.c; #20331).
     *
     * @var array<int, true>
     */
    private static array $childrenViews = [];

    /**
     * Namespace filter for live children() views.
     * null / `''` ⇒ unprefixed element children only (default xmlns included; #22737);
     * non-empty ⇒ URI/prefix filter.
     *
     * @var array<int, array{ns: ?string, isPrefix: bool}>
     */
    private static array $childrenViewFilters = [];

    /**
     * Live `$sxe->childName` property views — parent node + local name filter.
     * Length/iteration/offsetUnset re-read matching siblings (php-src sxe.c; #20483).
     *
     * @var array<int, true>
     */
    private static array $namedChildViews = [];

    /** @var array<int, string> */
    private static array $namedChildViewNames = [];

    /**
     * When set, `$parent->name` was taken from a children() view — resolve by local name
     * within that namespace filter (php-src sxe.c; #22728 / #22829). Absent ⇒ exact QName
     * match among the parent's children (non-namespaced property access).
     *
     * @var array<int, array{ns: ?string, isPrefix: bool}>
     */
    private static array $namedChildViewFilters = [];

    /** @var array<int, int> */
    private static array $documentKeys = [];

    /** @var array<int, array<string, string>> */
    private static array $xpathNamespaces = [];

    public static function reset(): void
    {
        self::$states = [];
        self::$views = [];
        self::$attributeViews = [];
        self::$attributeNodeViews = [];
        self::$attributeNodeNames = [];
        self::$attributeViewFilters = [];
        self::$childrenViews = [];
        self::$childrenViewFilters = [];
        self::$namedChildViews = [];
        self::$namedChildViewNames = [];
        self::$namedChildViewFilters = [];
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

    public static function attachAttributesView(
        ObjectEntry $entry,
        SimpleXmlNodeState $state,
        ?int $documentKey = null,
        ?string $namespaceOrPrefix = null,
        bool $isPrefix = true
    ): void {
        self::attach($entry, $state, $documentKey);
        self::$attributeViews[$entry->id] = true;
        self::$attributeViewFilters[$entry->id] = [
            'ns' => $namespaceOrPrefix,
            'isPrefix' => $isPrefix,
        ];
    }

    /**
     * Live `$sxe['attr']` / attributes() property handle (php-src sxe_prop_dim_read; #22654).
     */
    public static function attachAttributeNodeView(
        ObjectEntry $entry,
        SimpleXmlNodeState $owner,
        string $attrName,
        ?int $documentKey = null
    ): void {
        self::attach($entry, $owner, $documentKey);
        self::$attributeNodeViews[$entry->id] = true;
        self::$attributeNodeNames[$entry->id] = $attrName;
    }

    public static function attributeNodeName(ObjectEntry $entry): string
    {
        return self::$attributeNodeNames[$entry->id] ?? '';
    }

    /** @return array{ns: ?string, isPrefix: bool} */
    public static function attributesViewFilter(ObjectEntry $entry): array
    {
        return self::$attributeViewFilters[$entry->id] ?? ['ns' => null, 'isPrefix' => true];
    }

    public static function attachChildrenView(
        ObjectEntry $entry,
        SimpleXmlNodeState $parent,
        ?int $documentKey = null,
        ?string $namespaceOrPrefix = null,
        bool $isPrefix = true
    ): void {
        self::attach($entry, $parent, $documentKey);
        self::$childrenViews[$entry->id] = true;
        self::$childrenViewFilters[$entry->id] = [
            'ns' => $namespaceOrPrefix,
            'isPrefix' => $isPrefix,
        ];
    }

    /** @return array{ns: ?string, isPrefix: bool} */
    public static function childrenViewFilter(ObjectEntry $entry): array
    {
        return self::$childrenViewFilters[$entry->id] ?? ['ns' => null, 'isPrefix' => true];
    }

    /**
     * @param array{ns: ?string, isPrefix: bool}|null $childrenFilter Namespace filter
     *        inherited from a live children() view, or null for exact QName property access.
     */
    public static function attachNamedChildView(
        ObjectEntry $entry,
        SimpleXmlNodeState $parent,
        string $childName,
        ?int $documentKey = null,
        ?array $childrenFilter = null
    ): void {
        self::attach($entry, $parent, $documentKey);
        self::$namedChildViews[$entry->id] = true;
        self::$namedChildViewNames[$entry->id] = $childName;
        if (null !== $childrenFilter) {
            self::$namedChildViewFilters[$entry->id] = $childrenFilter;
        }
    }

    public static function namedChildViewName(ObjectEntry $entry): string
    {
        return self::$namedChildViewNames[$entry->id] ?? '';
    }

    /** @return array{ns: ?string, isPrefix: bool}|null */
    public static function namedChildViewFilter(ObjectEntry $entry): ?array
    {
        return self::$namedChildViewFilters[$entry->id] ?? null;
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
        return isset(self::$views[$entry->id])
            || isset(self::$childrenViews[$entry->id])
            || isset(self::$namedChildViews[$entry->id]);
    }

    public static function isChildrenView(ObjectEntry $entry): bool
    {
        return isset(self::$childrenViews[$entry->id]);
    }

    public static function isNamedChildView(ObjectEntry $entry): bool
    {
        return isset(self::$namedChildViews[$entry->id]);
    }

    public static function isAttributesView(ObjectEntry $entry): bool
    {
        return isset(self::$attributeViews[$entry->id]);
    }

    public static function isAttributeNodeView(ObjectEntry $entry): bool
    {
        return isset(self::$attributeNodeViews[$entry->id]);
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
        // php-src sxe.c / xmlXPathRegisterNs — empty prefix fails and returns false (#31656).
        if ('' === $prefix) {
            return false;
        }
        $key = self::documentKey($entry);
        self::$xpathNamespaces[$key][$prefix] = $namespaceUri;

        return true;
    }
}

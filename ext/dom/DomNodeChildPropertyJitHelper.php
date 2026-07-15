<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** DOMNode::$firstChild / $lastChild for user-script AOT live tree reads (#18951). */
final class DomNodeChildPropertyJitHelper
{
    public static function firstChildArgv(ObjectEntry $node): ?ObjectEntry
    {
        return self::childArgv($node, true);
    }

    public static function lastChildArgv(ObjectEntry $node): ?ObjectEntry
    {
        return self::childArgv($node, false);
    }

    public static function firstChildByIdArgv(int $nodeId): ?ObjectEntry
    {
        $node = DomRegistry::entry($nodeId);

        return null === $node ? null : self::childArgv($node, true);
    }

    public static function lastChildByIdArgv(int $nodeId): ?ObjectEntry
    {
        $node = DomRegistry::entry($nodeId);

        return null === $node ? null : self::childArgv($node, false);
    }

    private static function childArgv(ObjectEntry $node, bool $first): ?ObjectEntry
    {
        $node = DomRegistry::entry($node->id) ?? $node;
        if (!DomRegistry::has($node)) {
            return null;
        }
        $state = DomRegistry::state($node);
        $childIds = $state->childIds;
        if ([] === $childIds) {
            return null;
        }
        $childId = $first ? $childIds[0] : $childIds[\count($childIds) - 1];

        return DomRegistry::entry($childId);
    }

    /** Live parentNode after removeChild/replaceChild (#19240). */
    public static function parentNodeArgv(ObjectEntry $node): ?ObjectEntry
    {
        $node = DomRegistry::entry($node->id) ?? $node;
        if (!DomRegistry::has($node)) {
            return null;
        }
        $parentId = DomRegistry::state($node)->parentId;
        if (null === $parentId) {
            return null;
        }

        return DomRegistry::entry($parentId);
    }

    /** Live nextSibling for detached/attached nodes (#19240). */
    public static function nextSiblingArgv(ObjectEntry $node): ?ObjectEntry
    {
        return self::siblingArgv($node, true);
    }

    /** Live previousSibling for detached/attached nodes (#19240). */
    public static function previousSiblingArgv(ObjectEntry $node): ?ObjectEntry
    {
        return self::siblingArgv($node, false);
    }

    private static function siblingArgv(ObjectEntry $node, bool $next): ?ObjectEntry
    {
        $node = DomRegistry::entry($node->id) ?? $node;
        if (!DomRegistry::has($node)) {
            return null;
        }
        $state = DomRegistry::state($node);
        if (null === $state->parentId) {
            return null;
        }
        $parent = DomRegistry::entry($state->parentId);
        if (null === $parent || !DomRegistry::has($parent)) {
            return null;
        }
        $childIds = DomRegistry::state($parent)->childIds;
        $index = \array_search($node->id, $childIds, true);
        if (false === $index) {
            return null;
        }
        $sibId = $next
            ? ($childIds[$index + 1] ?? null)
            : ($childIds[$index - 1] ?? null);
        if (null === $sibId) {
            return null;
        }

        return DomRegistry::entry($sibId);
    }
}

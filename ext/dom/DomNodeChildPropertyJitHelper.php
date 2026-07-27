<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** DOMNode::$firstChild / $lastChild for user-script AOT live tree reads (#18951). */
final class DomNodeChildPropertyJitHelper
{
    public static function firstChildArgv(ObjectEntry $node): ?ObjectEntry
    {
        VmDom::ensureFetchableNode($node);

        return self::retainChild(self::childArgv($node, true));
    }

    public static function lastChildArgv(ObjectEntry $node): ?ObjectEntry
    {
        VmDom::ensureFetchableNode($node);

        return self::retainChild(self::childArgv($node, false));
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
        VmDom::ensureFetchableNode($node);
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
        VmDom::ensureFetchableNode($node);

        return self::retainChild(self::siblingArgv($node, true));
    }

    /** Live previousSibling for detached/attached nodes (#19240). */
    public static function previousSiblingArgv(ObjectEntry $node): ?ObjectEntry
    {
        VmDom::ensureFetchableNode($node);

        return self::retainChild(self::siblingArgv($node, false));
    }

    /**
     * AOT/JIT property helpers hand out ObjectEntry* without going through VM ASSIGN, so
     * retainUserHandleFromVariable never runs — count the hand-out as a user handle so
     * textContent's php_libxml_node_free_list simulation can keep the first held child
     * and invalidate later siblings (#23892 / #23817).
     */
    private static function retainChild(?ObjectEntry $child): ?ObjectEntry
    {
        if (null !== $child) {
            VmDom::retainUserHandle($child);
        }

        return $child;
    }

    /** ParentNode::$firstElementChild (#19431). */
    public static function firstElementChildArgv(ObjectEntry $node): ?ObjectEntry
    {
        VmDom::ensureFetchableNode($node);
        $node = DomRegistry::entry($node->id) ?? $node;
        if (!DomRegistry::has($node)) {
            return null;
        }

        return self::firstElementInChildIds(DomRegistry::state($node)->childIds);
    }

    /** ParentNode::$lastElementChild (#19431). */
    public static function lastElementChildArgv(ObjectEntry $node): ?ObjectEntry
    {
        VmDom::ensureFetchableNode($node);
        $node = DomRegistry::entry($node->id) ?? $node;
        if (!DomRegistry::has($node)) {
            return null;
        }

        return self::lastElementInChildIds(DomRegistry::state($node)->childIds);
    }

    /** ParentNode::$childElementCount (#19431). */
    public static function childElementCountArgv(ObjectEntry $node): int
    {
        VmDom::ensureFetchableNode($node);
        $node = DomRegistry::entry($node->id) ?? $node;
        if (!DomRegistry::has($node)) {
            return 0;
        }
        $count = 0;
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && VmDom::isElement($child)) {
                ++$count;
            }
        }

        return $count;
    }

    /** NonDocumentTypeChildNode::$nextElementSibling (#19431). */
    public static function nextElementSiblingArgv(ObjectEntry $node): ?ObjectEntry
    {
        VmDom::ensureFetchableNode($node);

        return self::elementSiblingArgv($node, true);
    }

    /** NonDocumentTypeChildNode::$previousElementSibling (#19431). */
    public static function previousElementSiblingArgv(ObjectEntry $node): ?ObjectEntry
    {
        VmDom::ensureFetchableNode($node);

        return self::elementSiblingArgv($node, false);
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

    private static function elementSiblingArgv(ObjectEntry $node, bool $next): ?ObjectEntry
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
        if ($next) {
            for ($i = $index + 1, $n = \count($childIds); $i < $n; ++$i) {
                $sib = DomRegistry::entry($childIds[$i]);
                if (null !== $sib && VmDom::isElement($sib)) {
                    return $sib;
                }
            }

            return null;
        }
        for ($i = $index - 1; $i >= 0; --$i) {
            $sib = DomRegistry::entry($childIds[$i]);
            if (null !== $sib && VmDom::isElement($sib)) {
                return $sib;
            }
        }

        return null;
    }

    /** @param list<int> $childIds */
    private static function firstElementInChildIds(array $childIds): ?ObjectEntry
    {
        foreach ($childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && VmDom::isElement($child)) {
                return $child;
            }
        }

        return null;
    }

    /** @param list<int> $childIds */
    private static function lastElementInChildIds(array $childIds): ?ObjectEntry
    {
        for ($i = \count($childIds) - 1; $i >= 0; --$i) {
            $child = DomRegistry::entry($childIds[$i]);
            if (null !== $child && VmDom::isElement($child)) {
                return $child;
            }
        }

        return null;
    }
}

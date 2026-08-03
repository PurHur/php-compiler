<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOMNodeList::item() for compiled JIT/AOT modules (#18493, #27410).
 *
 * SSOT: {@see VmDom::nodeListItem()}
 * php-src: ext/dom/nodelist.c — dom_nodelist_item
 */
final class DomNodeListItemJitHelper
{
    public static function itemIntArgv(ObjectEntry $nodeList, int $index): ?ObjectEntry
    {
        if ($index < 0) {
            return null;
        }

        // Thin-AOT childNodes: pinned item slots (#27410).
        $pinned = self::itemViaPinnedSlot($nodeList, $index);
        if (null !== $pinned) {
            return $pinned;
        }

        $viaOwner = VmDom::nodeListItemViaChildNodesOwner($nodeList, $index);
        if (null !== $viaOwner) {
            return $viaOwner;
        }
        if ($nodeList->hasProperty(VmDom::PROP_CHILD_NODES_OWNER)) {
            $ownerVar = $nodeList->getProperty(VmDom::PROP_CHILD_NODES_OWNER)->resolveIndirect();
            if (Variable::TYPE_OBJECT === $ownerVar->type) {
                return null;
            }
        }

        return VmDom::nodeListItem($nodeList, $index);
    }

    private static function itemViaPinnedSlot(ObjectEntry $nodeList, int $index): ?ObjectEntry
    {
        $prop = match ($index) {
            0 => '__phpcItem0',
            1 => '__phpcItem1',
            default => null,
        };
        if (null === $prop || !$nodeList->hasProperty($prop)) {
            return null;
        }
        $var = $nodeList->getProperty($prop)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return null;
        }

        return $var->toObject();
    }
}

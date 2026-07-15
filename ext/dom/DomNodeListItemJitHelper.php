<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/**
 * DOMNodeList::item() for compiled JIT/AOT modules (#18493).
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

        return VmDom::nodeListItem($nodeList, $index);
    }
}

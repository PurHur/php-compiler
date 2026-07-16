<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMNode::isEqualNode() nested helper for user-script AOT (#19507).
 * php-src: ext/dom/node.c — dom_node_is_equal_node
 */
final class DomIsEqualNodeJitHelper
{
    public static function isEqualNodeArgv(Context $ctx, ObjectEntry $node, ObjectEntry $other): int
    {
        unset($ctx);
        $canonical = DomRegistry::entry($node->id) ?? $node;
        $otherCanon = DomRegistry::entry($other->id) ?? $other;
        if (VmDom::isEqualNode($canonical, $otherCanon)) {
            return 1;
        }

        return 0;
    }
}

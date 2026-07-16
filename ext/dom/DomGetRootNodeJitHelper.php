<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMNode::getRootNode() nested helper for user-script AOT (#19507).
 * php-src: ext/dom/node.c — dom_node_get_root_node
 */
final class DomGetRootNodeJitHelper
{
    public static function getRootNodeArgv(Context $ctx, ObjectEntry $node): ObjectEntry
    {
        unset($ctx);
        $canonical = DomRegistry::entry($node->id) ?? $node;

        return VmDom::getRootNode($canonical);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** DOMNode::$baseURI for user-script AOT — php-src dom_node_base_uri_read (#34904 / maintainer_gap_dom_base_uri). */
final class DomNodeBaseUriJitHelper
{
    public static function baseUriArgv(ObjectEntry $node): string
    {
        return VmDom::readBaseUri($node);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::getNodePath() — absolute XPath-like path (php-src ext/dom/node.c; #14410). */
final class NodeGetNodePath extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getNodePath');
    }

    public function execute(Frame $frame): void
    {
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::getNodePath()');
        if (null === $frame->returnVar) {
            return;
        }
        $path = VmDom::getNodePath($node);
        if (null === $path) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($path);
    }
}

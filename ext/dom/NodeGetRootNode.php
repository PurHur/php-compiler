<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::getRootNode() — document root lookup (php-src ext/dom/node.c; #14449). */
final class NodeGetRootNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getRootNode');
    }

    public function execute(Frame $frame): void
    {
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::getRootNode()');
        if (null === $frame->returnVar) {
            return;
        }
        $root = VmDom::getRootNode($node);
        $frame->returnVar->object($root);
    }
}

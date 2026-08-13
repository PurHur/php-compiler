<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::hasChildNodes() — child presence probe (php-src ext/dom/node.c; #14418). */
final class NodeHasChildNodes extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('hasChildNodes');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNode::hasChildNodes', 0);
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::hasChildNodes()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::hasChildNodes($receiver));
    }
}

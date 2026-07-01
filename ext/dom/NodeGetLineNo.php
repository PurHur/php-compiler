<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::getLineNo() — VM (#14407, php-src ext/dom/node.c). */
final class NodeGetLineNo extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getLineNo');
    }

    public function execute(Frame $frame): void
    {
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::getLineNo()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmDom::getLineNo($node));
    }
}

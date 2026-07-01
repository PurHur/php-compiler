<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::hasAttributes() — attribute presence probe (php-src ext/dom/node.c; #14469). */
final class NodeHasAttributes extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('hasAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::hasAttributes()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::hasAttributes($receiver));
    }
}

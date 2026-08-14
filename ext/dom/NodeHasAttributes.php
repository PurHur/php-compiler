<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMNode::hasAttributes() — attribute presence probe (php-src ext/dom/node.c; #14469).
 *
 * Exact user arity 0 — Zend ArgumentCountError (#31011; missed by #30616).
 */
final class NodeHasAttributes extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('hasAttributes');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNode::hasAttributes', 0);
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::hasAttributes()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::hasAttributes($receiver));
    }
}

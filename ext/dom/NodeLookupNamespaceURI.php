<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMNode::lookupNamespaceURI() — VM (#14313, php-src ext/dom/node.c).
 *
 * Exact user arity 1 — Zend ArgumentCountError (#31251; re-#31011).
 */
final class NodeLookupNamespaceURI extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('lookupNamespaceURI');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNode::lookupNamespaceURI', 1);
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::lookupNamespaceURI()');
        $prefix = $this->nullableStringArg($frame->calledArgs[1], 'DOMNode::lookupNamespaceURI()', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $uri = VmDom::lookupNamespaceURI($node, $prefix);
        if (null === $uri) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($uri);
        }
    }
}

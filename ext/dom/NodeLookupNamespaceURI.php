<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::lookupNamespaceURI() — VM (#14313, php-src ext/dom/node.c). */
final class NodeLookupNamespaceURI extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('lookupNamespaceURI');
    }

    public function execute(Frame $frame): void
    {
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::lookupNamespaceURI()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMNode::lookupNamespaceURI() expects at least 1 argument');
        }
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

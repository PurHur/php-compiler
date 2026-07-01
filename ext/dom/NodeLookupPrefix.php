<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::lookupPrefix() — VM (#14313, php-src ext/dom/node.c). */
final class NodeLookupPrefix extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('lookupPrefix');
    }

    public function execute(Frame $frame): void
    {
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::lookupPrefix()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMNode::lookupPrefix() expects at least 1 argument');
        }
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'DOMNode::lookupPrefix()', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $prefix = VmDom::lookupPrefix($node, $namespace);
        if (null === $prefix) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($prefix);
        }
    }
}

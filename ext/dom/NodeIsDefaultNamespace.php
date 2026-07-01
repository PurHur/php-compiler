<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::isDefaultNamespace() — default namespace probe (php-src ext/dom/node.c; #14456). */
final class NodeIsDefaultNamespace extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('isDefaultNamespace');
    }

    public function execute(Frame $frame): void
    {
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::isDefaultNamespace()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMNode::isDefaultNamespace() expects exactly 1 argument');
        }
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'DOMNode::isDefaultNamespace()', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::isDefaultNamespace($node, $namespace));
    }
}

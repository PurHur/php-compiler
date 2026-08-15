<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMNode::isDefaultNamespace() — default namespace probe (php-src ext/dom/node.c; #14456).
 *
 * Exact user arity 1 — Zend ArgumentCountError (#31251; re-#31011).
 */
final class NodeIsDefaultNamespace extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('isDefaultNamespace');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNode::isDefaultNamespace', 1);
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::isDefaultNamespace()');
        $namespace = $this->stringArg(
            $frame->calledArgs[1],
            'DOMNode::isDefaultNamespace()',
            0,
            $frame,
            'namespace'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::isDefaultNamespace($node, $namespace));
    }
}

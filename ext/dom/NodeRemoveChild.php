<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::removeChild() — tree mutation (php-src ext/dom/node.c; #14394). */
final class NodeRemoveChild extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('removeChild');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::removeChild()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMNode::removeChild() expects exactly 1 argument');
        }
        $child = $this->requireDomNodeArg($frame->calledArgs[1], 'DOMNode::removeChild', 1, 'child');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::removeChild() requires VM context in this compiler build');
        }
        $removed = VmDom::removeChild($frame->vmContext, $receiver, $child);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($removed);
        }
    }
}

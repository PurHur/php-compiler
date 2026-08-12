<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::replaceChild() — tree mutation (php-src ext/dom/node.c; #14394). */
final class NodeReplaceChild extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('replaceChild');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::replaceChild()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMNode::replaceChild() expects exactly 2 arguments');
        }
        $newChild = $this->requireDomNodeArg($frame->calledArgs[1], 'DOMNode::replaceChild', 1, 'node');
        $oldChild = $this->requireDomNodeArg($frame->calledArgs[2], 'DOMNode::replaceChild', 2, 'child');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::replaceChild() requires VM context in this compiler build');
        }
        $removed = VmDom::replaceChild($frame->vmContext, $receiver, $newChild, $oldChild);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($removed);
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::appendChild() — VM (#19178, php-src ext/dom/node.c). */
final class NodeAppendChild extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('appendChild');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::appendChild()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMNode::appendChild() expects exactly 1 argument');
        }
        $child = $this->requireDomNodeArg($frame->calledArgs[1], 'DOMNode::appendChild', 1, 'node');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::appendChild() requires VM context in this compiler build');
        }
        $appended = VmDom::appendChild($frame->vmContext, $receiver, $child);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($appended);
        }
    }
}

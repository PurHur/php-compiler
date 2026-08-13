<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMNode::insertBefore() — tree mutation (php-src ext/dom/node.c; #14394). */
final class NodeInsertBefore extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('insertBefore');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'DOMNode::insertBefore', 1, 2);
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::insertBefore()');
        $newChild = $this->requireDomNodeArg($frame->calledArgs[1], 'DOMNode::insertBefore', 1, 'node');
        $refChild = null;
        if (isset($frame->calledArgs[2])) {
            $refVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $refVar->type) {
                // php-src stub: ?DOMNode $child
                $refChild = $this->requireDomNodeArg($frame->calledArgs[2], 'DOMNode::insertBefore', 2, 'child');
            }
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::insertBefore() requires VM context in this compiler build');
        }
        $inserted = VmDom::insertBefore($frame->vmContext, $receiver, $newChild, $refChild);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($inserted);
        }
    }
}

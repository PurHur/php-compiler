<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** DOMNode::compareDocumentPosition() — document-order bitmask (php-src ext/dom/node.c; #14448). */
final class NodeCompareDocumentPosition extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('compareDocumentPosition');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::compareDocumentPosition()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMNode::compareDocumentPosition() expects 1 argument');
        }
        $otherVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $otherVar->type) {
            throw new \TypeError(
                'DOMNode::compareDocumentPosition(): Argument #1 ($other) must be of type DOMNode, '
                .VmDom::typeLabel($otherVar).' given'
            );
        }
        $other = $otherVar->toObject();
        if (!VmDom::isDomNode($other)) {
            throw new \TypeError(
                'DOMNode::compareDocumentPosition(): Argument #1 ($other) must be of type DOMNode, '
                .VmDom::typeLabel($otherVar).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmDom::compareDocumentPosition($receiver, $other));
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::appendChild() — VM (#11895, php-src ext/dom/node.c). */
final class ElementAppendChild extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('appendChild');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNode::appendChild', 1);
        $receiver = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::appendChild()');
        // Declaring class is DOMNode — Zend TypeError cites DOMNode::appendChild (#30410).
        $child = $this->requireDomNodeArg($frame->calledArgs[1], 'DOMNode::appendChild', 1, 'node');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMElement::appendChild() requires VM context in this compiler build');
        }
        $appended = VmDom::appendChild($frame->vmContext, $receiver, $child);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($appended);
        }
    }
}

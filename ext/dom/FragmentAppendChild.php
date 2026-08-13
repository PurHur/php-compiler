<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocumentFragment::appendChild() — VM (#6317, php-src ext/dom/node.c). */
final class FragmentAppendChild extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('appendChild');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNode::appendChild', 1);
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT_FRAGMENT, 'DOMDocumentFragment::appendChild()');
        // Declaring class is DOMNode — Zend TypeError cites DOMNode::appendChild (#30410).
        $child = $this->requireDomNodeArg($frame->calledArgs[1], 'DOMNode::appendChild', 1, 'node');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocumentFragment::appendChild() requires VM context in this compiler build');
        }
        $appended = VmDom::appendChild($frame->vmContext, $receiver, $child);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($appended);
        }
    }
}

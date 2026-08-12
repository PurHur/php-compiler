<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::appendChild() — VM (#11895, php-src ext/dom/node.c). */
final class DocumentAppendChild extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('appendChild');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::appendChild()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::appendChild() expects exactly 1 argument');
        }
        // Declaring class is DOMNode — Zend TypeError cites DOMNode::appendChild (#30410).
        $child = $this->requireDomNodeArg($frame->calledArgs[1], 'DOMNode::appendChild', 1, 'node');
        VmDom::ensureDocument($receiver);
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::appendChild() requires VM context in this compiler build');
        }
        $appended = VmDom::appendChild($frame->vmContext, $receiver, $child);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($appended);
        }
    }
}

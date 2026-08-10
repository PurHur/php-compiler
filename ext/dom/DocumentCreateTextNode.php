<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::createTextNode() — VM (#6250, php-src ext/dom/node.c). */
final class DocumentCreateTextNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createTextNode');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createTextNode()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::createTextNode() expects at least 1 argument');
        }
        // Pass $frame so caller strict_types rejects null like Zend (#29985, re-#29942).
        $data = $this->stringArg(
            $frame->calledArgs[1],
            'DOMDocument::createTextNode()',
            0,
            $frame,
            'data'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createTextNode() requires VM context in this compiler build');
        }
        $text = VmDom::createTextNode($frame->vmContext, $data, $document);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($text);
        }
    }
}

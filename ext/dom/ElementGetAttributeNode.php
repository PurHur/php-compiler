<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::getAttributeNode() — VM (#14455, php-src ext/dom/attr.c). */
final class ElementGetAttributeNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributeNode');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::getAttributeNode()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMElement::getAttributeNode() expects at least 1 argument');
        }
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMElement::getAttributeNode()',
            0,
            $frame,
            'qualifiedName'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMElement::getAttributeNode() requires VM context in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmDom::getAttributeNode($frame->vmContext, $element, $name));
    }
}

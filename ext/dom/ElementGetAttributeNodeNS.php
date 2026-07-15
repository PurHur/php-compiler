<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::getAttributeNodeNS() — VM (php-src ext/dom/element.c; #19265). */
final class ElementGetAttributeNodeNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributeNodeNS');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::getAttributeNodeNS()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('DOMElement::getAttributeNodeNS() expects exactly 2 arguments, ' . (\count($frame->calledArgs) - 1) . ' given');
        }
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'DOMElement::getAttributeNodeNS()', 0);
        $localName = $this->stringArg($frame->calledArgs[2], 'DOMElement::getAttributeNodeNS()', 1);
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMElement::getAttributeNodeNS() requires VM context in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmDom::getAttributeNodeNS($frame->vmContext, $element, $namespace, $localName));
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMElement::getAttributeNodeNS() — VM (php-src ext/dom/element.c; #19265).
 *
 * Exact user arity 2 — Zend ArgumentCountError (#31032; missed by #31011).
 */
final class ElementGetAttributeNodeNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributeNodeNS');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMElement::getAttributeNodeNS', 2);
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::getAttributeNodeNS()');
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'DOMElement::getAttributeNodeNS()', 0);
        $localName = $this->stringArg(
            $frame->calledArgs[2],
            'DOMElement::getAttributeNodeNS()',
            1,
            $frame,
            'localName'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMElement::getAttributeNodeNS() requires VM context in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmDom::getAttributeNodeNS($frame->vmContext, $element, $namespace, $localName));
    }
}

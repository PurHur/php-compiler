<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::removeAttributeNS() — VM (#15291, php-src ext/dom/element.c). */
final class ElementRemoveAttributeNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('removeAttributeNS');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::removeAttributeNS()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMElement::removeAttributeNS() expects at least 2 arguments');
        }
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'DOMElement::removeAttributeNS()', 0);
        // Pass $frame so caller strict_types rejects null localName (#30091, peer #29985).
        $localName = $this->stringArg(
            $frame->calledArgs[2],
            'DOMElement::removeAttributeNS()',
            1,
            $frame,
            'localName'
        );
        VmDom::removeAttributeNS($frame->vmContext, $element, $namespace, $localName);
        if (null !== $frame->returnVar) {
            // php-src dom_element_remove_attribute_ns() returns SUCCESS → null (#15358).
            $frame->returnVar->null();
        }
    }
}

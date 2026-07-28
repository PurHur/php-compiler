<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::setAttributeNS() — VM (#14313, php-src ext/dom/php_dom.c). */
final class ElementSetAttributeNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('setAttributeNS');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::setAttributeNS()');
        if (\count($frame->calledArgs) < 4) {
            throw new \LogicException('DOMElement::setAttributeNS() expects at least 3 arguments');
        }
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'DOMElement::setAttributeNS()', 0);
        $qualifiedName = $this->stringArg($frame->calledArgs[2], 'DOMElement::setAttributeNS()', 1);
        $value = $this->stringArg($frame->calledArgs[3], 'DOMElement::setAttributeNS()', 2);
        // php-src element.c — name_len == 0 → ValueError on arg #2 (#24480).
        VmDom::rejectEmptyQualifiedName($qualifiedName, 'DOMElement::setAttributeNS', 2);
        VmDom::setAttributeNS($frame->vmContext, $element, $namespace, $qualifiedName, $value);
    }
}

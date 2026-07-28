<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::setAttribute() — VM (#14543, php-src ext/dom/php_dom.c). */
final class ElementSetAttribute extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('setAttribute');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::setAttribute()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMElement::setAttribute() expects at least 2 arguments');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'DOMElement::setAttribute()', 0);
        $value = $this->stringArg($frame->calledArgs[2], 'DOMElement::setAttribute()', 1);
        // php-src element.c — name_len == 0 → ValueError (#24480).
        VmDom::rejectEmptyQualifiedName($name, 'DOMElement::setAttribute', 1);
        VmDom::setAttributeNS($frame->vmContext, $element, null, $name, $value);
    }
}

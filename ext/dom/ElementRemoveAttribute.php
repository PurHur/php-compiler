<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::removeAttribute() — VM (#15297, php-src ext/dom/element.c). */
final class ElementRemoveAttribute extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('removeAttribute');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::removeAttribute()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMElement::removeAttribute() expects at least 1 argument');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'DOMElement::removeAttribute()', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::removeAttributeNS($frame->vmContext, $element, null, $name));
    }
}

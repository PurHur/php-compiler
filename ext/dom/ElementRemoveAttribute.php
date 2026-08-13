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
        $this->requireExactUserArgCount($frame, 'DOMElement::removeAttribute', 1);
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::removeAttribute()');
        // Pass $frame so caller strict_types rejects null like Zend (#29985, re-#29942).
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMElement::removeAttribute()',
            0,
            $frame,
            'qualifiedName'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::removeAttributeNS($frame->vmContext, $element, null, $name));
    }
}

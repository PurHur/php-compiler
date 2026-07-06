<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::getAttributeNames() — VM (php-src ext/dom/element.c; #16823). */
final class ElementGetAttributeNames extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributeNames');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::getAttributeNames()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmDom::getAttributeNames($element));
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::hasAttribute() — VM (#15297, php-src ext/dom/element.c). */
final class ElementHasAttribute extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('hasAttribute');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::hasAttribute()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMElement::hasAttribute() expects at least 1 argument');
        }
        // Pass $frame so caller strict_types rejects null like Zend (#29985, re-#29942).
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMElement::hasAttribute()',
            0,
            $frame,
            'qualifiedName'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::hasAttribute($element, $name));
    }
}

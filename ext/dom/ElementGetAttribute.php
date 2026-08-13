<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::getAttribute() — VM (#14543, php-src ext/dom/php_dom.c). */
final class ElementGetAttribute extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttribute');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMElement::getAttribute', 1);
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::getAttribute()');
        // Pass $frame so caller strict_types rejects null like Zend (#29985, re-#29942).
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMElement::getAttribute()',
            0,
            $frame,
            'qualifiedName'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $value = VmDom::getAttribute($element, $name);
        if (null === $value) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($value);
        }
    }
}

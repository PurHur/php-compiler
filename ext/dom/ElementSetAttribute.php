<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::setAttribute() — VM (#14543, #24538, php-src ext/dom/element.c). */
final class ElementSetAttribute extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('setAttribute');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMElement::setAttribute', 2);
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::setAttribute()');
        // Pass $frame so caller strict_types rejects null like Zend (#29985, re-#29942).
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMElement::setAttribute()',
            0,
            $frame,
            'qualifiedName'
        );
        $value = $this->stringArg(
            $frame->calledArgs[2],
            'DOMElement::setAttribute()',
            1,
            $frame,
            'value'
        );
        // php-src element.c — name_len == 0 → ValueError (#24480).
        VmDom::rejectEmptyQualifiedName($name, 'DOMElement::setAttribute', 1);
        // php-src DOM_RET_OBJ / xmlns → true (#24538).
        $result = VmDom::setAttribute($frame->vmContext, $element, $name, $value);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($result);
    }
}

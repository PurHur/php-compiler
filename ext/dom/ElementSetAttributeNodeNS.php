<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMElement::setAttributeNodeNS() — VM (php-src ext/dom/element.c; #19265). */
final class ElementSetAttributeNodeNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('setAttributeNodeNS');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::setAttributeNodeNS()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('DOMElement::setAttributeNodeNS() expects exactly 1 argument, ' . (\count($frame->calledArgs) - 1) . ' given');
        }
        $attrVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $attrVar->type) {
            throw new \TypeError('DOMElement::setAttributeNodeNS(): Argument #1 ($attr) must be of type DOMAttr');
        }
        $attr = $attrVar->toObject();
        if (!VmDom::isAttr($attr)) {
            throw new \TypeError('DOMElement::setAttributeNodeNS(): Argument #1 ($attr) must be of type DOMAttr');
        }
        if (null === $frame->returnVar) {
            VmDom::setAttributeNodeNS($frame->vmContext, $element, $attr);

            return;
        }
        $frame->returnVar->copyFrom(VmDom::setAttributeNodeNS($frame->vmContext, $element, $attr));
    }
}

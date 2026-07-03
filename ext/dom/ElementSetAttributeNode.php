<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMElement::setAttributeNode() — VM (#14455, php-src ext/dom/attr.c). */
final class ElementSetAttributeNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('setAttributeNode');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::setAttributeNode()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMElement::setAttributeNode() expects at least 1 argument');
        }
        $attrVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $attrVar->type) {
            throw new \TypeError('DOMElement::setAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        $attr = $attrVar->toObject();
        if (!VmDom::isAttr($attr)) {
            throw new \TypeError('DOMElement::setAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        if (null === $frame->returnVar) {
            VmDom::setAttributeNode($element, $attr);

            return;
        }
        $frame->returnVar->copyFrom(VmDom::setAttributeNode($element, $attr));
    }
}

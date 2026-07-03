<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMElement::removeAttributeNode() — VM (#14455, php-src ext/dom/attr.c). */
final class ElementRemoveAttributeNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('removeAttributeNode');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::removeAttributeNode()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMElement::removeAttributeNode() expects at least 1 argument');
        }
        $attrVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $attrVar->type) {
            throw new \TypeError('DOMElement::removeAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        $attr = $attrVar->toObject();
        if (!VmDom::isAttr($attr)) {
            throw new \TypeError('DOMElement::removeAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        if (null === $frame->returnVar) {
            VmDom::removeAttributeNode($element, $attr);

            return;
        }
        $frame->returnVar->copyFrom(VmDom::removeAttributeNode($element, $attr));
    }
}

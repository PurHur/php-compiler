<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMElement::setIdAttributeNode() — VM (php-src ext/dom/element.c; #20123). */
final class ElementSetIdAttributeNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('setIdAttributeNode');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::setIdAttributeNode()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'DOMElement::setIdAttributeNode() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $attrVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $attrVar->type) {
            throw new \TypeError(
                'DOMElement::setIdAttributeNode(): Argument #1 ($attr) must be of type DOMAttr, '
                .VmDom::typeLabel($attrVar).' given'
            );
        }
        $attr = $attrVar->toObject();
        if (!VmDom::isAttr($attr)) {
            throw new \TypeError(
                'DOMElement::setIdAttributeNode(): Argument #1 ($attr) must be of type DOMAttr, '
                .$attr->class->name.' given'
            );
        }
        $isIdVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $isIdVar->type) {
            throw new \TypeError(
                'DOMElement::setIdAttributeNode(): Argument #2 ($isId) must be of type bool, '
                .VmDom::typeLabel($isIdVar).' given'
            );
        }
        VmDom::setIdAttributeNode($element, $attr, $isIdVar->toBool());
    }
}

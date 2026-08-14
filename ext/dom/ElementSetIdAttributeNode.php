<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMElement::setIdAttributeNode() — VM (php-src ext/dom/element.c; #20123).
 *
 * Exact user arity 2 — Zend ArgumentCountError (#31032; missed by #31011).
 */
final class ElementSetIdAttributeNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('setIdAttributeNode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMElement::setIdAttributeNode', 2);
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::setIdAttributeNode()');
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

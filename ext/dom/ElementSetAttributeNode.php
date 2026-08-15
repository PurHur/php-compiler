<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMElement::setAttributeNode() — VM (#14455, php-src ext/dom/attr.c).
 *
 * Exact user arity 1 — Zend ArgumentCountError (#31251; re-#31011).
 */
final class ElementSetAttributeNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('setAttributeNode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMElement::setAttributeNode', 1);
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::setAttributeNode()');
        $attrVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $attrVar->type) {
            throw new \TypeError('DOMElement::setAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        $attr = $attrVar->toObject();
        if (!VmDom::isAttr($attr)) {
            throw new \TypeError('DOMElement::setAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        if (null === $frame->returnVar) {
            VmDom::setAttributeNode($frame->vmContext, $element, $attr);

            return;
        }
        $frame->returnVar->copyFrom(VmDom::setAttributeNode($frame->vmContext, $element, $attr));
    }
}

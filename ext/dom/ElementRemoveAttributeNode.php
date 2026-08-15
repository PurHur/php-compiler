<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMElement::removeAttributeNode() — VM (#14455, php-src ext/dom/attr.c).
 *
 * Exact user arity 1 — Zend ArgumentCountError (#31251; re-#31011).
 */
final class ElementRemoveAttributeNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('removeAttributeNode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMElement::removeAttributeNode', 1);
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::removeAttributeNode()');
        $attrVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $attrVar->type) {
            throw new \TypeError('DOMElement::removeAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        $attr = $attrVar->toObject();
        if (!VmDom::isAttr($attr)) {
            throw new \TypeError('DOMElement::removeAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        if (null === $frame->returnVar) {
            VmDom::removeAttributeNode($frame->vmContext, $element, $attr);

            return;
        }
        $frame->returnVar->copyFrom(VmDom::removeAttributeNode($frame->vmContext, $element, $attr));
    }
}

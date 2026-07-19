<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMElement::insertAdjacentElement() — PHP 8.4 Living Standard element insertion (php-src ext/dom/php_dom.c; #16865). */
final class ElementInsertAdjacentElement extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('insertAdjacentElement');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::insertAdjacentElement()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMElement::insertAdjacentElement() expects at least 2 arguments');
        }
        $position = $this->adjacentWhereArg($receiver, $frame->calledArgs[1], 'insertAdjacentElement');
        $elementArg = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_NULL === $elementArg->type) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->null();
            }

            return;
        }
        if (Variable::TYPE_OBJECT !== $elementArg->type) {
            throw new \TypeError(sprintf(
                'DOMElement::insertAdjacentElement(): Argument #2 ($element) must be of type ?DOMElement, %s given',
                VmDom::typeLabel($elementArg)
            ));
        }
        $nodeElement = $elementArg->toObject();
        if (!VmDom::isElement($nodeElement)) {
            throw new \TypeError(sprintf(
                'DOMElement::insertAdjacentElement(): Argument #2 ($element) must be of type ?DOMElement, %s given',
                $nodeElement->class->name
            ));
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMElement::insertAdjacentElement() requires VM context in this compiler build');
        }
        $inserted = VmDom::insertAdjacentElement($frame->vmContext, $receiver, $position, $nodeElement);
        if (null !== $frame->returnVar) {
            if (null === $inserted) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->object($inserted);
            }
        }
    }
}

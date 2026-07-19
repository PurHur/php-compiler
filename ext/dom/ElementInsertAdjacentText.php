<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::insertAdjacentText() — PHP 8.4 Living Standard text insertion (php-src ext/dom/element.c; #16914). */
final class ElementInsertAdjacentText extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('insertAdjacentText');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::insertAdjacentText()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMElement::insertAdjacentText() expects at least 2 arguments');
        }
        $position = $this->adjacentPositionArg(
            $receiver,
            $frame->calledArgs[1],
            'insertAdjacentText',
            $frame
        );
        $data = $this->stringArg($frame->calledArgs[2], 'DOMElement::insertAdjacentText()', 1, $frame, 'data');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMElement::insertAdjacentText() requires VM context in this compiler build');
        }
        VmDom::insertAdjacentText($frame->vmContext, $receiver, $position, $data);
    }
}

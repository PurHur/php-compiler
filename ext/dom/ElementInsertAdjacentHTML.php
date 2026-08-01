<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::insertAdjacentHTML() — PHP 8.5+ HTML insertion (php-src ext/dom/php_dom.stub.php; #26063, re-#16128). */
final class ElementInsertAdjacentHTML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('insertAdjacentHTML');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::insertAdjacentHTML()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMElement::insertAdjacentHTML() expects at least 2 arguments');
        }
        $position = $this->adjacentHtmlPositionArg(
            $frame->calledArgs[1],
            'DOMElement::insertAdjacentHTML()',
            0,
            $frame,
            'position'
        );
        $html = $this->stringArg($frame->calledArgs[2], 'DOMElement::insertAdjacentHTML()', 1, $frame, 'html');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMElement::insertAdjacentHTML() requires VM context in this compiler build');
        }
        VmDom::insertAdjacentHTML($frame->vmContext, $receiver, $position, $html);
    }
}

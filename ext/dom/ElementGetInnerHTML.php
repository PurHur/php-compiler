<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::getInnerHTML() — VM (php-src ext/dom/inner_html_mixin.c; #16916). */
final class ElementGetInnerHTML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerHTML');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::getInnerHTML()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmDom::getInnerHTML($element));
    }
}

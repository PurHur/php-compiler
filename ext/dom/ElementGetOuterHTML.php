<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::getOuterHTML() — VM (php-src ext/dom/inner_html_mixin.c; #16916). */
final class ElementGetOuterHTML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getOuterHTML');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::getOuterHTML()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmDom::getOuterHTML($element));
    }
}

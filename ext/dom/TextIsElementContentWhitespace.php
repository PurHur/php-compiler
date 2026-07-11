<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMText::isElementContentWhitespace() — legacy alias (#17543, php-src ext/dom/text.c). */
final class TextIsElementContentWhitespace extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('isElementContentWhitespace');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMText::isElementContentWhitespace()');
        if (!VmDom::isTextOrCdataNode($receiver)) {
            throw new \TypeError('DOMText::isElementContentWhitespace() must be called on a text node');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::textIsWhitespaceInElementContent($receiver));
    }
}

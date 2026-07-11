<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMText::isWhitespaceInElementContent() — VM (#17543, php-src ext/dom/text.c). */
final class TextIsWhitespaceInElementContent extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('isWhitespaceInElementContent');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMText::isWhitespaceInElementContent()');
        if (!VmDom::isTextOrCdataNode($receiver)) {
            throw new \TypeError('DOMText::isWhitespaceInElementContent() must be called on a text node');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::textIsWhitespaceInElementContent($receiver));
    }
}

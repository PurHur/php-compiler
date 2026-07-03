<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::saveHTMLFile() — VM (#15333, php-src ext/dom/php_dom.c). */
final class DocumentSaveHTMLFile extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('saveHTMLFile');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::saveHTMLFile()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::saveHTMLFile() expects exactly 1 argument');
        }
        $filename = $this->stringArg($frame->calledArgs[1], 'DOMDocument::saveHTMLFile()', 0);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmDom::saveHTMLFile($receiver, $filename));
        }
    }
}

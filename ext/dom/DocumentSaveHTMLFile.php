<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMDocument::saveHTMLFile() — VM (#15333, php-src ext/dom/php_dom.c).
 *
 * Exact user arity 1 — Zend ArgumentCountError (#31251; re-#31011).
 */
final class DocumentSaveHTMLFile extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('saveHTMLFile');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMDocument::saveHTMLFile', 1);
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::saveHTMLFile()');
        $filename = $this->stringArg(
            $frame->calledArgs[1],
            'DOMDocument::saveHTMLFile()',
            0,
            $frame,
            'filename'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmDom::saveHTMLFile($receiver, $filename));
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::saveHTML() — VM (#14356, php-src ext/dom/php_dom.c). */
final class DocumentSaveHTML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('saveHTML');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtMostUserArgCount($frame, 'DOMDocument::saveHTML', 1);
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::saveHTML()');
        [$node, $options] = $this->parseSaveNodeAndOptionsArgs($frame, 'DOMDocument::saveHTML()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmDom::saveHTML($receiver, $node, $options));
        }
    }
}

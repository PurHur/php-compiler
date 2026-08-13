<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::saveXML() — VM (#6140). */
final class DocumentSaveXML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('saveXML');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtMostUserArgCount($frame, 'DOMDocument::saveXML', 2);
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::saveXML()');
        [$node, $options] = $this->parseSaveNodeAndOptionsArgs($frame, 'DOMDocument::saveXML()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmDom::saveXML($receiver, $node, $options));
        }
    }
}

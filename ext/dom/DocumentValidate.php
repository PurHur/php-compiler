<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::validate() — in-document DTD validation (php-src ext/dom/document.c; #18833). */
final class DocumentValidate extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('validate');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMDocument::validate', 0);
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::validate()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::validate() requires VM context in this compiler build');
        }
        $ok = VmDom::validate($frame->vmContext, $document, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

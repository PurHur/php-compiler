<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::relaxNGValidateSource() — in-memory RelaxNG validation (php-src ext/dom/document.c; #18748). */
final class DocumentRelaxNGValidateSource extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('relaxNGValidateSource');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::relaxNGValidateSource()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('DOMDocument::relaxNGValidateSource() expects at least 1 argument, 0 given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::relaxNGValidateSource() requires VM context in this compiler build');
        }
        $source = $this->stringArg($frame->calledArgs[1], 'DOMDocument::relaxNGValidateSource()', 0, $frame, 'source');
        $ok = VmDom::relaxNGValidateSource($frame->vmContext, $document, $source, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

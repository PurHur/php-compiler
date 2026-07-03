<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::relaxNGValidate() — RelaxNG validation (php-src ext/dom/document.c; #14370). */
final class DocumentRelaxNGValidate extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('relaxNGValidate');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::relaxNGValidate()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('DOMDocument::relaxNGValidate() expects exactly 1 argument, 0 given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::relaxNGValidate() requires VM context in this compiler build');
        }
        $filename = $this->stringArg($frame->calledArgs[1], 'DOMDocument::relaxNGValidate()', 0, $frame, 'filename');
        $ok = VmDom::relaxNGValidate($frame->vmContext, $document, $filename, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

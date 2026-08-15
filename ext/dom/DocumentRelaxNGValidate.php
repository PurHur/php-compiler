<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMDocument::relaxNGValidate() — RelaxNG validation (php-src ext/dom/document.c; #14370).
 *
 * Exact user arity 1 — Zend ArgumentCountError (#31251; re-#31011).
 */
final class DocumentRelaxNGValidate extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('relaxNGValidate');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMDocument::relaxNGValidate', 1);
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::relaxNGValidate()');
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

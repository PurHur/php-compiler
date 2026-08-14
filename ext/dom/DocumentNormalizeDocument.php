<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMDocument::normalizeDocument() — tree-wide normalize (php-src ext/dom/document.c; #14370).
 *
 * Exact user arity 0 — Zend ArgumentCountError (#31011; missed by #30616).
 */
final class DocumentNormalizeDocument extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('normalizeDocument');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMDocument::normalizeDocument', 0);
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::normalizeDocument()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::normalizeDocument() requires VM context in this compiler build');
        }
        VmDom::normalizeDocument($frame->vmContext, $document);
    }
}

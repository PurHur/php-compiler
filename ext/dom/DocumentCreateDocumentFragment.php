<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMDocument::createDocumentFragment() — VM (#6317, php-src ext/dom/document.c).
 *
 * Exact user arity 0 — Zend ArgumentCountError (#31251; re-#31011).
 */
final class DocumentCreateDocumentFragment extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createDocumentFragment');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMDocument::createDocumentFragment', 0);
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createDocumentFragment()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createDocumentFragment() requires VM context in this compiler build');
        }
        $fragment = VmDom::createDocumentFragment($frame->vmContext, $document);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($fragment);
        }
    }
}

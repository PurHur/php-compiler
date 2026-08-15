<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMDocument::createEntityReference() — VM (#15240, php-src ext/dom/document.c).
 *
 * Exact user arity 1 — Zend ArgumentCountError (#31251; re-#31011).
 */
final class DocumentCreateEntityReference extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createEntityReference');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMDocument::createEntityReference', 1);
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createEntityReference()');
        VmDom::ensureDocument($document);
        $name = $this->stringArg($frame->calledArgs[1], 'DOMDocument::createEntityReference()', 0, $frame, 'name');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createEntityReference() requires VM context in this compiler build');
        }
        $ref = VmDom::createEntityReference($frame->vmContext, $name, $document);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($ref);
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::createEntityReference() — VM (#15240, php-src ext/dom/document.c). */
final class DocumentCreateEntityReference extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createEntityReference');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createEntityReference()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::createEntityReference() expects exactly 1 argument');
        }
        VmDom::ensureDocument($document);
        $name = $this->stringArg($frame->calledArgs[1], 'DOMDocument::createEntityReference()', 0, $frame, 'name');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createEntityReference() requires VM context in this compiler build');
        }
        $entityRef = VmDom::createEntityReference($frame->vmContext, $name, $document);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($entityRef);
        }
    }
}

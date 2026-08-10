<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::createAttributeNS() — VM (#15253, php-src ext/dom/document.c). */
final class DocumentCreateAttributeNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createAttributeNS');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createAttributeNS()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMDocument::createAttributeNS() expects at least 2 arguments');
        }
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'DOMDocument::createAttributeNS()', 0);
        // Pass $frame so caller strict_types rejects null like Zend (#29985, re-#29942).
        $qualifiedName = $this->stringArg(
            $frame->calledArgs[2],
            'DOMDocument::createAttributeNS()',
            1,
            $frame,
            'qualifiedName'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createAttributeNS() requires VM context in this compiler build');
        }
        $attr = VmDom::documentCreateAttributeNS(
            $frame->vmContext,
            $document,
            $namespace,
            $qualifiedName,
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($attr);
        }
    }
}

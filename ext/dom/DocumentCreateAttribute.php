<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::createAttribute() — VM (#14455, php-src ext/dom/attr.c). */
final class DocumentCreateAttribute extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createAttribute');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMDocument::createAttribute', 1);
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createAttribute()');
        // Pass $frame so caller strict_types rejects null like Zend (#29985, re-#29942).
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMDocument::createAttribute()',
            0,
            $frame,
            'localName'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createAttribute() requires VM context in this compiler build');
        }
        $attr = VmDom::createAttribute($frame->vmContext, $name, $document);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($attr);
        }
    }
}

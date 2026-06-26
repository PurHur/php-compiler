<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::createElement() — VM (#11895, php-src ext/dom/document.c). */
final class DocumentCreateElement extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createElement');
    }

    public function execute(Frame $frame): void
    {
        $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createElement()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::createElement() expects at least 1 argument');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'DOMDocument::createElement()', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createElement() requires VM context in this compiler build');
        }
        $element = VmDom::createElement($frame->vmContext, $name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($element);
        }
    }
}

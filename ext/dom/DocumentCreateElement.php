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
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createElement()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::createElement() expects at least 1 argument');
        }
        // Pass $frame so caller strict_types rejects null like Zend (#29985, re-#29942).
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMDocument::createElement()',
            0,
            $frame,
            'localName'
        );
        $value = '';
        if (isset($frame->calledArgs[2])) {
            $value = $this->stringArg(
                $frame->calledArgs[2],
                'DOMDocument::createElement()',
                1,
                $frame,
                'value'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createElement() requires VM context in this compiler build');
        }
        $element = VmDom::createElement($frame->vmContext, $name, $document, $value);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($element);
        }
    }
}

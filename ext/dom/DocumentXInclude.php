<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::xinclude() — XInclude substitution count (php-src ext/dom/document.c; #14370). */
final class DocumentXInclude extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('xinclude');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtMostUserArgCount($frame, 'DOMDocument::xinclude', 1);
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::xinclude()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::xinclude() requires VM context in this compiler build');
        }
        $options = 0;
        if (isset($frame->calledArgs[1])) {
            // Z_PARAM_LONG $options (#25768).
            $options = $this->zParamLongArg($frame, 1, 'DOMDocument::xinclude', 1, 'options');
        }
        $count = VmDom::xinclude($frame->vmContext, $document, $options, $frame);
        if (null !== $frame->returnVar) {
            if (false === $count) {
                $frame->returnVar->bool(false);
            } else {
                $frame->returnVar->int($count);
            }
        }
    }
}

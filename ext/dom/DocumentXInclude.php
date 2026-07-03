<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMDocument::xinclude() — XInclude substitution count (php-src ext/dom/document.c; #14370). */
final class DocumentXInclude extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('xinclude');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::xinclude()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::xinclude() requires VM context in this compiler build');
        }
        $options = 0;
        if (isset($frame->calledArgs[1])) {
            $optionsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $optionsVar->type) {
                throw new \TypeError(sprintf(
                    'DOMDocument::xinclude() expects argument #1 to be of type int, %s given',
                    VmDom::typeLabel($optionsVar)
                ));
            }
            $options = $optionsVar->toInt();
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

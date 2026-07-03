<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMDocument::registerNodeClass() — VM (#15334, php-src ext/dom/document.c). */
final class DocumentRegisterNodeClass extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('registerNodeClass');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::registerNodeClass()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('DOMDocument::registerNodeClass() expects exactly 2 arguments, 1 given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::registerNodeClass() requires VM context in this compiler build');
        }
        $baseName = $this->stringArg($frame->calledArgs[1], 'DOMDocument::registerNodeClass()', 0, $frame, 'baseClass');
        $extendedArg = $frame->calledArgs[2]->resolveIndirect();
        $extendedName = null;
        if (Variable::TYPE_NULL !== $extendedArg->type) {
            $extendedName = $this->stringArg($frame->calledArgs[2], 'DOMDocument::registerNodeClass()', 1, $frame, 'extendedClass');
        }
        VmDom::registerNodeClass($frame->vmContext, $document, $baseName, $extendedName);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

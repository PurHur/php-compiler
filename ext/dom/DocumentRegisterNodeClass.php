<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMDocument::registerNodeClass() — VM (#15334, php-src ext/dom/document.c).
 *
 * Exact user arity 2 — Zend ArgumentCountError (#31251; re-#31011 / #26061).
 */
final class DocumentRegisterNodeClass extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('registerNodeClass');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::registerNodeClass()');
        // Living Dom\* receivers share this handler; error labels follow php-src (#26061).
        $function = VmDomLiving::isLivingDocument($document)
            ? 'Dom\\Document::registerNodeClass'
            : 'DOMDocument::registerNodeClass';
        $this->requireExactUserArgCount($frame, $function, 2);
        $label = $function.'()';
        if (null === $frame->vmContext) {
            throw new \LogicException($label.' requires VM context in this compiler build');
        }
        $baseName = $this->stringArg($frame->calledArgs[1], $label, 0, $frame, 'baseClass');
        $extendedArg = $frame->calledArgs[2]->resolveIndirect();
        $extendedName = null;
        if (Variable::TYPE_NULL !== $extendedArg->type) {
            $extendedName = $this->stringArg($frame->calledArgs[2], $label, 1, $frame, 'extendedClass');
        }
        VmDom::registerNodeClass($frame->vmContext, $document, $baseName, $extendedName);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

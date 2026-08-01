<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Dom\Implementation::createHTMLDocument() — php-src ext/dom/php_dom.stub.php (#20898).
 */
final class ImplementationCreateHTMLDocument extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createHTMLDocument');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('Dom\\Implementation::createHTMLDocument() requires VM context');
        }
        self::receiver($frame, VmDomLiving::CLASS_IMPLEMENTATION, 'Dom\\Implementation::createHTMLDocument()');
        $title = null;
        if (isset($frame->calledArgs[1])) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $title = $this->stringArg($frame->calledArgs[1], 'Dom\\Implementation::createHTMLDocument()', 0);
            }
        }
        $docVar = VmDomLiving::createHTMLDocument($frame->vmContext, $title);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($docVar);
        }
    }
}

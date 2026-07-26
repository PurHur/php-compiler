<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** XSLTProcessor::registerPHPFunctions() — VM (#19872, php-src ext/xsl/xsltprocessor.c). */
final class XsltProcessorRegisterPhpFunctions extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('registerPHPFunctions');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::registerPHPFunctions()');
        if (null === $frame->vmContext) {
            throw new \LogicException(
                'XSLTProcessor::registerPHPFunctions() requires VM context in this compiler build'
            );
        }
        $restrict = null;
        if (isset($frame->calledArgs[1])) {
            $restrict = $frame->calledArgs[1]->resolveIndirect();
        }
        VmXsl::registerPHPFunctions($frame->vmContext, $entry, $restrict);
    }
}

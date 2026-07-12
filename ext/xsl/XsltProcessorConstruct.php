<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;

/** XSLTProcessor::__construct() — VM (#3665, php-src ext/xsl/xsltprocessor.c). */
final class XsltProcessorConstruct extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('XSLTProcessor::__construct() called without $this');
        }
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        if (VmXsl::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError('XSLTProcessor::__construct(): Argument must be XSLTProcessor');
        }
        VmXsl::construct($entry);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;

/** XSLTProcessor::importStylesheet() — VM (#3665, php-src ext/xsl/xsltprocessor.c). */
final class XsltProcessorImportStylesheet extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('importStylesheet');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::importStylesheet()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('XSLTProcessor::importStylesheet() expects at least 2 arguments, 1 given');
        }
        $stylesheet = VmXslDomBridge::requireVmDocument(
            $frame->calledArgs[1],
            'XSLTProcessor::importStylesheet()'
        );
        VmXsl::importStylesheet($entry, $stylesheet);
    }
}

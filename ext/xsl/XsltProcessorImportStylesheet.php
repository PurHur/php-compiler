<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XSLTProcessor::importStylesheet() — VM (#3665/#22367, php-src ext/xsl/xsltprocessor.c).
 *
 * Returns bool matching Zend (true on success, false when document is not a stylesheet).
 */
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
        $ok = VmXsl::importStylesheet($entry, $stylesheet);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}

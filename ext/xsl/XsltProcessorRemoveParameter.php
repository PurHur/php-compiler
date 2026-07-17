<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/** XSLTProcessor::removeParameter() — VM (#19872, php-src ext/xsl/xsltprocessor.c). */
final class XsltProcessorRemoveParameter extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('removeParameter');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::removeParameter()');
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'XSLTProcessor::removeParameter() expects at least 3 arguments, '.($argc - 1).' given'
            );
        }
        $namespace = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'XSLTProcessor::removeParameter',
            1,
            'namespaceURI'
        );
        $name = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[2],
            'XSLTProcessor::removeParameter',
            2,
            'localName'
        );
        $result = VmXsl::removeParameter($entry, $namespace, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->bool($result);
        });
    }
}

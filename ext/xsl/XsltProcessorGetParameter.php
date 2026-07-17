<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/** XSLTProcessor::getParameter() — VM (#19872, php-src ext/xsl/xsltprocessor.c). */
final class XsltProcessorGetParameter extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('getParameter');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::getParameter()');
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'XSLTProcessor::getParameter() expects at least 3 arguments, '.($argc - 1).' given'
            );
        }
        $namespace = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'XSLTProcessor::getParameter',
            1,
            'namespaceURI'
        );
        $name = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[2],
            'XSLTProcessor::getParameter',
            2,
            'localName'
        );
        $result = VmXsl::getParameter($entry, $namespace, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }
}

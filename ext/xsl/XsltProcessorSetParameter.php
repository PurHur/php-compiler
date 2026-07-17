<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/** XSLTProcessor::setParameter() — VM (#19872, php-src ext/xsl/xsltprocessor.c). */
final class XsltProcessorSetParameter extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('setParameter');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::setParameter()');
        $argc = \count($frame->calledArgs);
        if ($argc < 4) {
            throw new \ArgumentCountError(
                'XSLTProcessor::setParameter() expects at least 4 arguments, '.($argc - 1).' given'
            );
        }
        $namespace = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'XSLTProcessor::setParameter',
            1,
            'namespace'
        );
        $name = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[2],
            'XSLTProcessor::setParameter',
            2,
            'name'
        );
        $value = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[3],
            'XSLTProcessor::setParameter',
            3,
            'value'
        );
        $result = VmXsl::setParameter($entry, $namespace, $name, $value);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->bool($result);
        });
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * XSLTProcessor::setProfiling(?string $filename): bool — VM (#22272).
 *
 * php-src: ext/xsl/xsltprocessor.c — PHP_METHOD(XSLTProcessor, setProfiling)
 * (zend_parse_parameters "P!" path-or-null; always RETURN_TRUE).
 */
final class XsltProcessorSetProfiling extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('setProfiling');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::setProfiling()');
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'XSLTProcessor::setProfiling() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'XSLTProcessor::setProfiling() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        $filename = VmString::typedNullableStringBuiltinArgForFrame(
            $frame,
            1,
            'XSLTProcessor::setProfiling',
            0,
            'filename'
        );
        $result = VmXsl::setProfiling($entry, $filename);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->bool($result);
        });
    }
}

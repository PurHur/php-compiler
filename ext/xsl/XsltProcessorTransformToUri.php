<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/** XSLTProcessor::transformToUri() — VM (#20391, php-src ext/xsl/xsltprocessor.c). */
final class XsltProcessorTransformToUri extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('transformToUri');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::transformToUri()');
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'XSLTProcessor::transformToUri() expects exactly 2 arguments, '.($argc - 1).' given'
            );
        }
        $document = VmXslDomBridge::requireVmDocument(
            $frame->calledArgs[1],
            'XSLTProcessor::transformToUri()'
        );
        $uri = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[2],
            'XSLTProcessor::transformToUri',
            2,
            'uri'
        );
        $result = VmXsl::transformToUri($entry, $document, $uri);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);
            } else {
                $ret->int($result);
            }
        });
    }
}

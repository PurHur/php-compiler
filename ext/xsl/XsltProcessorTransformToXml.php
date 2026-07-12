<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XSLTProcessor::transformToXML() — VM (#3665, php-src ext/xsl/xsltprocessor.c). */
final class XsltProcessorTransformToXml extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('transformToXML');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::transformToXML()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('XSLTProcessor::transformToXML() expects at least 2 arguments, 1 given');
        }
        $document = VmXslDomBridge::requireVmDocument(
            $frame->calledArgs[1],
            'XSLTProcessor::transformToXML()'
        );
        $result = VmXsl::transformToXml($entry, $document);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);
            } else {
                $ret->string($result);
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XSLTProcessor::transformToDoc() — VM (#3665, php-src ext/xsl/xsltprocessor.c). */
final class XsltProcessorTransformToDoc extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('transformToDoc');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::transformToDoc()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('XSLTProcessor::transformToDoc() expects at least 2 arguments, 1 given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('XSLTProcessor::transformToDoc() requires VM context in this compiler build');
        }
        $document = VmXslDomBridge::requireVmDocument(
            $frame->calledArgs[1],
            'XSLTProcessor::transformToDoc()'
        );
        $result = VmXsl::transformToDoc($frame->vmContext, $entry, $document);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);
            } else {
                $ret->object($result);
            }
        });
    }
}

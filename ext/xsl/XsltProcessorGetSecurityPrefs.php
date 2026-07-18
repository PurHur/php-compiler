<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XSLTProcessor::getSecurityPrefs() — VM (#20392, php-src ext/xsl/xsltprocessor.c). */
final class XsltProcessorGetSecurityPrefs extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('getSecurityPrefs');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::getSecurityPrefs()');
        $result = VmXsl::getSecurityPrefs($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->int($result);
        });
    }
}

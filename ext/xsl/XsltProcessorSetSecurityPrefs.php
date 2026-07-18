<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;

/** XSLTProcessor::setSecurityPrefs() — VM (#20392, php-src ext/xsl/xsltprocessor.c). */
final class XsltProcessorSetSecurityPrefs extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('setSecurityPrefs');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::setSecurityPrefs()');
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'XSLTProcessor::setSecurityPrefs() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        $prefs = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            1,
            'XSLTProcessor::setSecurityPrefs',
            1,
            'securityPrefs'
        );
        $result = VmXsl::setSecurityPrefs($entry, $prefs);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->int($result);
        });
    }
}

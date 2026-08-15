<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XSLTProcessor::hasExsltSupport() — VM (#20392, php-src ext/xsl/xsltprocessor.c).
 *
 * Exact user arity 0 — Zend ArgumentCountError (#30993).
 */
final class XsltProcessorHasExsltSupport extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('hasExsltSupport');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'XSLTProcessor::hasExsltSupport() expects exactly 0 arguments, '.($argc - 1).' given'
            );
        }
        $entry = $this->receiver($frame, 'XSLTProcessor::hasExsltSupport()');
        $result = VmXsl::hasExsltSupport($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->bool($result);
        });
    }
}

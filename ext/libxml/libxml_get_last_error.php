<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** libxml_get_last_error() — last buffered LibXMLError or false (php-src ext/libxml/libxml.c; #14186, #29161). */
final class libxml_get_last_error extends LibxmlFunction
{
    public function __construct()
    {
        parent::__construct('libxml_get_last_error');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'libxml_get_last_error() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmLibxml::getLastError($frame->vmContext));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLibxmlGetLastError::invoke($context, ...$args);
    }
}

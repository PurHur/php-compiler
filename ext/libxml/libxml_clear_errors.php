<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** libxml_clear_errors() — empty internal error buffer (php-src ext/libxml/libxml.c; #6058, #29161). */
final class libxml_clear_errors extends LibxmlFunction
{
    public function __construct()
    {
        parent::__construct('libxml_clear_errors');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'libxml_clear_errors() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        VmLibxml::clearErrors();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLibxmlClearErrors::invoke($context, ...$args);
    }
}

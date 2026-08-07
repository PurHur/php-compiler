<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** libxml_use_internal_errors() — toggle internal error buffer (php-src ext/libxml/libxml.c; #6058, #28659). */
final class libxml_use_internal_errors extends LibxmlFunction
{
    public function __construct()
    {
        parent::__construct('libxml_use_internal_errors');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError('libxml_use_internal_errors() expects at most 1 argument, '.$argc.' given');
        }
        $useErrors = null;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL === $arg->type) {
                $useErrors = null;
            } elseif (Variable::TYPE_BOOLEAN === $arg->type) {
                $useErrors = $arg->toBool();
            } else {
                $useErrors = (bool) $arg->toInt();
            }
        }
        $result = VmLibxml::useInternalErrors($useErrors);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLibxmlUseInternalErrors::invoke($context, ...$args);
    }
}

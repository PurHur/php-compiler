<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** connection_aborted() — detect closed HTTP connection (ext/standard/basic_functions.c; #3242). VM only v1. */
final class connection_aborted extends Internal
{
    public function __construct()
    {
        parent::__construct('connection_aborted');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'connection_aborted() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('connection_aborted() requires VM context');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($frame->vmContext->executionLimits->connectionAborted());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('connection_aborted() is not implemented for JIT in this compiler build (issue #3242)');
    }
}

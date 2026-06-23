<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** set_time_limit() — adjust max execution time (ext/standard/basic_functions.c; #3242, JIT #8078). */
final class set_time_limit extends Internal
{
    public function __construct()
    {
        parent::__construct('set_time_limit');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'set_time_limit() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('set_time_limit() requires VM context');
        }
        $seconds = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'set_time_limit', 1, 'seconds');
        if (null === $frame->returnVar) {
            return;
        }
        $ok = $frame->vmContext->executionLimits->setTimeLimit($frame->vmContext, $seconds);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitExecutionLimits::setTimeLimit($context, ...$args);
    }
}

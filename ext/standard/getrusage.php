<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** getrusage() — process resource usage (VM VmProcess; JIT/AOT GetrusageJitHelper, #5388/#9184). */
final class getrusage extends Internal
{
    public function __construct()
    {
        parent::__construct('getrusage');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('getrusage() accepts at most one argument in this compiler build');
        }
        $who = 0;
        if (1 === $argc) {
            // Z_PARAM_LONG $mode — caller strict_types → TypeError on null (#30361).
            $who = VmGetrusageArg::parseMode($frame, 0);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $usage = VmProcess::getrusage($who);
        if (false === $usage) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($usage);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('getrusage() accepts at most one argument in this compiler build');
        }

        return JitGetrusage::invoke($context, $args[0] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * getrusage() — process resource usage (VM VmProcess; JIT/AOT GetrusageJitHelper, #5388/#9184).
 *
 * Excess argc → Zend ArgumentCountError (#30537; php-src ext/standard/basic_functions.c).
 */
final class getrusage extends Internal
{
    public function __construct()
    {
        parent::__construct('getrusage');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 0..1 — #30537.
        $this->requireArgCountRange($frame, 'getrusage', 0, 1);
        $argc = \count($frame->calledArgs);
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
        // Catchable ArgumentCountError under AOT try/catch (#30537 / peer #30536).
        if (!$this->requireArgCountRangeJit($context, $args, 'getrusage', 0, 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitGetrusage::invoke($context, $args[0] ?? null);
    }
}

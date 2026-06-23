<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** sleep() — delay execution (VM via VmSleepPure; JIT/AOT via sleep(3)). */
final class sleep extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('sleep() requires exactly one argument');
        }
        $seconds = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'sleep', 1, 'seconds');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmSleep::sleep($seconds);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('sleep() requires exactly one argument');
        }

        return JitSleep::sleep($context, $args[0]);
    }
}

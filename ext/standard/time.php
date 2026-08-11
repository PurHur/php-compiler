<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** time() — Unix timestamp (VM VmDate; JIT/AOT TimeJitHelper via StringTime, #30332/#5045). */
final class time extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf('time() expects exactly 0 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmDate::time());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf('time() expects exactly 0 arguments, %d given', $argc));
        }

        return JitDate::time($context);
    }
}

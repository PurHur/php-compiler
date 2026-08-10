<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\MathLog10;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** log10() base-10 logarithm (ext/standard/math.c, issue #3578). */
final class log10 extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('log10() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::log10(VmMath::parseStrictFloatBuiltinArgForFrame($frame, 'log10', 1, 'num')));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('log10() requires exactly one argument');
        }
        $asFloat = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'log10', 'float');

        return MathLog10::invoke($context, $asFloat);
    }
}

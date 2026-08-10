<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\MathExpm1;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** expm1() exp(x)-1 (ext/standard/math.c, issue #3578). */
final class expm1 extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('expm1() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::expm1(VmMath::parseStrictFloatBuiltinArgForFrame($frame, 'expm1', 1, 'num')));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('expm1() requires exactly one argument');
        }
        $asFloat = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'expm1', 'float');

        return MathExpm1::invoke($context, $asFloat);
    }
}

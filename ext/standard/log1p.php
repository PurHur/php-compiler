<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\MathLog1p;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** log1p() log(1+x) (ext/standard/math.c, issue #3578). */
final class log1p extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('log1p() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::log1p(VmMath::parseStrictFloatBuiltinArgForFrame($frame, 'log1p', 1, 'num')));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('log1p() requires exactly one argument');
        }
        $asFloat = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'log1p', 'float');

        return MathLog1p::invoke($context, $asFloat);
    }
}

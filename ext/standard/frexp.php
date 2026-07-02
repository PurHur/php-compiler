<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\MathFrexp;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** frexp() split float into normalized fraction and exponent (ext/standard/math.c, issue #3578). */
final class frexp extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('frexp() requires exactly two arguments');
        }
        $num = VmMath::parseDoubleBuiltinArg($frame->calledArgs[0]->resolveIndirect(), 'frexp', 1, 'num');
        $exp = 0;
        $frac = VmMath::frexp($num, $exp);
        $frame->calledArgs[1]->resolveIndirect()->int($exp);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float($frac);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== \count($args)) {
            throw new \LogicException('frexp() requires exactly two arguments');
        }
        $double = $context->getTypeFromString('double');
        $num = pow::toJitDouble($context, $args[0], $double);
        $outPtr = JitValueBox::valuePtrFromVariable($context, $args[1]);

        return MathFrexp::invoke($context, $num, $outPtr);
    }
}

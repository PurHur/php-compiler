<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\MathModf;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** modf() split float into fraction and integer part (ext/standard/math.c, issue #3578). */
final class modf extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('modf() requires exactly two arguments');
        }
        $num = VmMath::parseDoubleBuiltinArg($frame->calledArgs[0]->resolveIndirect(), 'modf', 1, 'num');
        $intPart = 0.0;
        $frac = VmMath::modf($num, $intPart);
        $frame->calledArgs[1]->resolveIndirect()->float($intPart);
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
            throw new \LogicException('modf() requires exactly two arguments');
        }
        $double = $context->getTypeFromString('double');
        $num = pow::toJitDouble($context, $args[0], $double);
        $outPtr = JitValueBox::valuePtrFromVariable($context, $args[1]);

        return MathModf::invoke($context, $num, $outPtr);
    }
}

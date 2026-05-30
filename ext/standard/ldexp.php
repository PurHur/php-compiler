<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** ldexp() multiply by 2^exp (ext/standard/math.c, issue #3578). */
final class ldexp extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('ldexp() requires exactly two arguments');
        }
        $num = $frame->calledArgs[0]->resolveIndirect();
        $exp = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::ldexp(VmMath::toFloat($num), VmMath::toInt($exp)));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== \count($args)) {
            throw new \LogicException('ldexp() requires exactly two arguments');
        }
        $double = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $num = pow::toJitDouble($context, $args[0], $double);
        $expRaw = JitLongArg::lower($context, $args[1], 'ldexp() argument #2');
        $exp = $context->builder->trunc($expRaw, $i32);

        return $context->builder->call($context->lookupFunction('ldexp'), $num, $exp);
    }
}

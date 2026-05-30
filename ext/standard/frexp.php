<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
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
        $num = VmMath::toFloat($frame->calledArgs[0]->resolveIndirect());
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
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $num = pow::toJitDouble($context, $args[0], $double);
        $outPtr = JitValueBox::valuePtrFromVariable($context, $args[1]);
        $expSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $frac = $context->builder->call($context->lookupFunction('frexp'), $num, $expSlot);
        $expVal = $context->builder->load($expSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $context->builder->sext($expVal, $i64)
        );

        return $frac;
    }
}

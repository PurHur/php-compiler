<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
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
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\log1p(VmMath::toFloat($v)));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('log1p() requires exactly one argument');
        }
        $double = $context->getTypeFromString('double');
        $asFloat = pow::toJitDouble($context, $args[0], $double);

        return $context->builder->call($context->lookupFunction('log1p'), $asFloat);
    }
}

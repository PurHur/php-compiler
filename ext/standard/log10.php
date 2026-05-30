<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** log10() base-10 logarithm (ext/standard/math.c, issue #3578). */
final class log10 extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('log10() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\log10(VmMath::toFloat($v)));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('log10() requires exactly one argument');
        }
        $double = $context->getTypeFromString('double');
        $asFloat = pow::toJitDouble($context, $args[0], $double);

        return $context->builder->call($context->lookupFunction('log10'), $asFloat);
    }
}

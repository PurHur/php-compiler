<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * empty() for values supported by this compiler (subset of PHP).
 */
final class EmptyFunction extends Internal
{
    public function __construct()
    {
        parent::__construct('empty');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('empty() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(!boolval::isTruthy($v));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== count($args)) {
            throw new \LogicException('empty() requires exactly one argument');
        }
        $truthy = (new boolval())->call($context, ...$args);
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $truthy, $i1->constInt(0, false));
    }
}

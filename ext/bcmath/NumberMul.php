<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** BcMath\Number::mul(Number|string|int $num, ?int $scale = null) — VM (#7220). */
final class NumberMul extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('mul');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::mul()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('BcMath\\Number::mul() expects at least 1 argument, 0 given');
        }
        $right = $this->coerceOperand($frame, 1, 'BcMath\\Number::mul', 'num');
        $scale = $this->optionalScale($frame, 2, 'BcMath\\Number::mul');
        $effectiveScale = $this->effectiveMulScale($receiver, $right, $scale);
        $result = VmBcmath::mul(VmBcMathNumber::valueString($receiver), $right, $effectiveScale);
        $this->returnNumber($frame, $result, $effectiveScale);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** BcMath\Number::div(Number|string|int $num, ?int $scale = null) — VM (#7220). */
final class NumberDiv extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('div');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::div()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('BcMath\\Number::div() expects at least 1 argument, 0 given');
        }
        $right = $this->coerceOperand($frame, 1, 'BcMath\\Number::div', 'num');
        $scale = $this->optionalScale($frame, 2, 'BcMath\\Number::div');
        $left = VmBcMathNumber::valueString($receiver);
        $leftScale = VmBcMathNumber::objectScale($receiver);
        $rightScale = VmBcmath::decimalScale($right);
        if (null !== $scale) {
            $result = VmBcmath::div($left, $right, $scale);
            $this->returnNumber($frame, $result, $scale);

            return;
        }
        [$result, $objectScale] = VmBcMathNumber::computeBinary(
            \PHPCompiler\OpCode::TYPE_DIV,
            $left,
            $leftScale,
            $right,
            $rightScale,
            false
        );
        $this->returnNumber($frame, $result, $objectScale);
    }
}

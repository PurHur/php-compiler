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
        $explicitScale = $this->optionalScale($frame, 2, 'BcMath\\Number::div');
        $scale = VmBcMathNumber::resolveBinaryScale('div', $receiver, $frame->calledArgs[1], $explicitScale);
        $result = VmBcmath::div(VmBcMathNumber::valueString($receiver), $right, $scale);
        $this->returnNumber($frame, $result, $scale);
    }
}

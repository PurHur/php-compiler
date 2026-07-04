<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** BcMath\Number::sub(Number|string|int $num, ?int $scale = null) — VM (#7220). */
final class NumberSub extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('sub');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::sub()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('BcMath\\Number::sub() expects at least 1 argument, 0 given');
        }
        $right = $this->coerceOperand($frame, 1, 'BcMath\\Number::sub', 'num');
        $explicitScale = $this->optionalScale($frame, 2, 'BcMath\\Number::sub');
        $scale = VmBcMathNumber::resolveBinaryScale('sub', $receiver, $frame->calledArgs[1], $explicitScale);
        $result = VmBcmath::sub(VmBcMathNumber::valueString($receiver), $right, $scale);
        $this->returnNumber($frame, $result, $scale);
    }
}

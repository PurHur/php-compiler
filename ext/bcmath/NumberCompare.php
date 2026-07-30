<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** BcMath\Number::compare(Number|string|int $num, ?int $scale = null) — VM (#7220). */
final class NumberCompare extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('compare');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::compare()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('BcMath\\Number::compare() expects at least 1 argument, 0 given');
        }
        $right = $this->coerceOperand($frame, 1, 'BcMath\\Number::compare', 'num');
        $scale = $this->optionalScale($frame, 2, 'BcMath\\Number::compare');
        $result = VmBcmath::compNumber(VmBcMathNumber::valueString($receiver), $right, $scale);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($result);
        }
    }
}

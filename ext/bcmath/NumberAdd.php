<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** BcMath\Number::add(Number|string|int $num, ?int $scale = null) — VM (#7220). */
final class NumberAdd extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('add');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::add()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('BcMath\\Number::add() expects at least 1 argument, 0 given');
        }
        $right = $this->coerceOperand($frame, 1, 'BcMath\\Number::add', 'num');
        $scale = $this->optionalScale($frame, 2, 'BcMath\\Number::add');
        $effectiveScale = $this->effectiveAddSubScale($receiver, $right, $scale);
        $result = VmBcmath::add(VmBcMathNumber::valueString($receiver), $right, $effectiveScale);
        $this->returnNumber($frame, $result, $effectiveScale);
    }
}

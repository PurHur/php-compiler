<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/**
 * BcMath\Number::mod(Number|string|int $num, ?int $scale = null) — VM (#19582).
 *
 * php-src: ext/bcmath/bcmath.c PHP_METHOD(BcMath_Number, mod) / bcmath_number_mod_internal.
 */
final class NumberMod extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('mod');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::mod()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('BcMath\\Number::mod() expects at least 1 argument, 0 given');
        }
        $right = $this->coerceOperand($frame, 1, 'BcMath\\Number::mod', 'num');
        $scale = $this->optionalScale($frame, 2, 'BcMath\\Number::mod');
        $effectiveScale = $this->effectiveModScale($receiver, $right, $scale);
        $result = VmBcmath::mod(VmBcMathNumber::valueString($receiver), $right, $effectiveScale);
        $this->returnNumber($frame, $result, $effectiveScale);
    }
}

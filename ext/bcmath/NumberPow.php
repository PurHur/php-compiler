<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/**
 * BcMath\Number::pow(Number|string|int $exponent, ?int $scale = null) — VM (#19582).
 *
 * php-src: ext/bcmath/bcmath.c PHP_METHOD(BcMath_Number, pow) / bcmath_number_pow_internal.
 */
final class NumberPow extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('pow');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::pow()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('BcMath\\Number::pow() expects at least 1 argument, 0 given');
        }
        $exponent = $this->coerceOperand($frame, 1, 'BcMath\\Number::pow', 'exponent');
        if (VmBcmath::decimalScale($exponent) > 0) {
            throw new \ValueError(
                'BcMath\\Number::pow(): Argument #1 ($exponent) exponent cannot have a fractional part'
            );
        }
        $scale = $this->optionalScale($frame, 2, 'BcMath\\Number::pow');
        $effectiveScale = $this->effectivePowScale($receiver, $exponent, $scale);
        $result = VmBcmath::pow(VmBcMathNumber::valueString($receiver), $exponent, $effectiveScale);
        $this->returnNumber($frame, $result, $effectiveScale);
    }
}

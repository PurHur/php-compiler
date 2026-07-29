<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/**
 * BcMath\Number::powmod(Number|string|int $exponent, Number|string|int $modulus, ?int $scale = null) — VM (#24612).
 *
 * php-src: ext/bcmath/bcmath.c PHP_METHOD(BcMath_Number, powmod) / bc_raisemod.
 */
final class NumberPowmod extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('powmod');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::powmod()');
        if (\count($frame->calledArgs) < 3) {
            $given = max(0, \count($frame->calledArgs) - 1);
            throw new \ArgumentCountError(
                'BcMath\\Number::powmod() expects at least 2 arguments, '.$given.' given'
            );
        }
        $base = VmBcMathNumber::valueString($receiver);
        $exponent = $this->coerceOperand($frame, 1, 'BcMath\\Number::powmod', 'exponent');
        $modulus = $this->coerceOperand($frame, 2, 'BcMath\\Number::powmod', 'modulus');
        $scale = $this->optionalScale($frame, 3, 'BcMath\\Number::powmod');
        // php-src: scale_is_null leaves scale_lval at 0 (not bcscale default).
        $effectiveScale = $scale ?? 0;

        if (VmBcmath::hasNonZeroFraction($base)) {
            throw new \ValueError('Base number cannot have a fractional part');
        }
        if (VmBcmath::hasNonZeroFraction($exponent)) {
            throw new \ValueError(
                'BcMath\\Number::powmod(): Argument #1 ($exponent) cannot have a fractional part'
            );
        }
        if (VmBcmath::hasNonZeroFraction($modulus)) {
            throw new \ValueError(
                'BcMath\\Number::powmod(): Argument #2 ($modulus) cannot have a fractional part'
            );
        }
        // Negative exponent — mirror php-src EXPO_IS_NEGATIVE before calling bcpowmod path.
        if (str_starts_with(ltrim($exponent, '+'), '-')) {
            throw new \ValueError(
                'BcMath\\Number::powmod(): Argument #1 ($exponent) must be greater than or equal to 0'
            );
        }

        $result = VmBcmath::powmod($base, $exponent, $modulus, $effectiveScale);
        $this->returnNumber($frame, $result, $effectiveScale);
    }
}

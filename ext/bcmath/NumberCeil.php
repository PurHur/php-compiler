<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/**
 * BcMath\Number::ceil() — VM (#19582).
 *
 * php-src: ext/bcmath/bcmath.c PHP_METHOD(BcMath_Number, ceil).
 */
final class NumberCeil extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('ceil');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::ceil()');
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'BcMath\\Number::ceil() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $result = VmBcmath::ceil(VmBcMathNumber::valueString($receiver));
        $this->returnNumber($frame, $result, 0);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/**
 * BcMath\Number::floor() — VM (#19582).
 *
 * php-src: ext/bcmath/bcmath.c PHP_METHOD(BcMath_Number, floor).
 */
final class NumberFloor extends BcMathNumberMethod
{
    public function __construct()
    {
        parent::__construct('floor');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'BcMath\\Number::floor()');
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'BcMath\\Number::floor() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $result = VmBcmath::floor(VmBcMathNumber::valueString($receiver));
        $this->returnNumber($frame, $result, 0);
    }
}

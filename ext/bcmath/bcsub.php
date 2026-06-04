<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
/** bcsub() — subtract two arbitrary precision numbers (php-src ext/bcmath/bcmath.c; issue #3365). */
final class bcsub extends BcmathFunction
{
    public function __construct()
    {
        parent::__construct('bcsub');
    }

    protected function compute(Frame $frame): string
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('bcsub() requires two or three arguments in this compiler build');
        }
        $left = $this->requireStringArg($frame, 0, 'num1');
        $right = $this->requireStringArg($frame, 1, 'num2');

        return VmBcmath::sub($left, $right, $this->optionalScale($frame, 2));
    }
}

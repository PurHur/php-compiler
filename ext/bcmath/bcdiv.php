<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
/** bcdiv() — divide two arbitrary precision numbers (php-src ext/bcmath/bcmath.c; issue #3365). */
final class bcdiv extends BcmathFunction
{
    public function __construct()
    {
        parent::__construct('bcdiv');
    }

    protected function compute(Frame $frame): string
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('bcdiv() requires two or three arguments in this compiler build');
        }
        $left = $this->requireStringArg($frame, 0, 'num1');
        $right = $this->requireStringArg($frame, 1, 'num2');

        return VmBcmath::div($left, $right, $this->optionalScale($frame, 2));
    }
}

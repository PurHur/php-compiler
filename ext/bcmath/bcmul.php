<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
/** bcmul() — multiply two arbitrary precision numbers (php-src ext/bcmath/bcmath.c; issue #3365). */
final class bcmul extends BcmathFunction
{
    public function __construct()
    {
        parent::__construct('bcmul');
    }

    protected function compute(Frame $frame): string
    {
        $this->requireBinaryArgCount($frame);
        $left = $this->requireStringArg($frame, 0, 'num1');
        $right = $this->requireStringArg($frame, 1, 'num2');

        return VmBcmath::mul(
            $left,
            $right,
            $this->optionalScale($frame, 2)
        );
    }
}

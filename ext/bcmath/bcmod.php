<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** bcmod() — remainder of arbitrary precision division (php-src ext/bcmath/bcmath.c; issue #6042). */
final class bcmod extends BcmathFunction
{
    public function __construct()
    {
        parent::__construct('bcmod');
    }

    protected function compute(Frame $frame): string
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('bcmod() requires two or three arguments in this compiler build');
        }
        $left = $this->requireStringArg($frame, 0, 'num1');
        $right = $this->requireStringArg($frame, 1, 'num2');

        return VmBcmath::mod($left, $right, $this->optionalScale($frame, 2));
    }
}

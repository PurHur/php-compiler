<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** bcfloor() — round decimal string down (php-src ext/bcmath/bcmath.c; issue #6026). */
final class bcfloor extends BcmathFunction
{
    public function __construct()
    {
        parent::__construct('bcfloor');
    }

    protected function compute(Frame $frame): string
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('bcfloor() requires exactly one argument in this compiler build');
        }

        return VmBcmath::floor($this->requireStringArg($frame, 0, 'num'));
    }
}

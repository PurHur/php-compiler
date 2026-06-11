<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** bcceil() — round decimal string up (php-src ext/bcmath/bcmath.c; issue #6026). */
final class bcceil extends BcmathFunction
{
    public function __construct()
    {
        parent::__construct('bcceil');
    }

    protected function compute(Frame $frame): string
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('bcceil() requires exactly one argument in this compiler build');
        }

        return VmBcmath::ceil($this->requireStringArg($frame, 0, 'num'));
    }
}

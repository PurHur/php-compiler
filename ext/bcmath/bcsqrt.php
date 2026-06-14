<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** bcsqrt() — arbitrary precision square root (php-src ext/bcmath/bcmath.c; issue #6042). */
final class bcsqrt extends BcmathFunction
{
    public function __construct()
    {
        parent::__construct('bcsqrt');
    }

    protected function compute(Frame $frame): string
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('bcsqrt() requires one or two arguments in this compiler build');
        }
        $num = $this->requireStringArg($frame, 0, 'num');

        return VmBcmath::sqrt($num, $this->optionalScale($frame, 1));
    }
}

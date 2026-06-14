<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** bcpow() — arbitrary precision exponentiation (php-src ext/bcmath/bcmath.c; issue #6042). */
final class bcpow extends BcmathFunction
{
    public function __construct()
    {
        parent::__construct('bcpow');
    }

    protected function compute(Frame $frame): string
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('bcpow() requires two or three arguments in this compiler build');
        }
        $base = $this->requireStringArg($frame, 0, 'num');
        $exponent = $this->requireStringArg($frame, 1, 'exponent');

        return VmBcmath::pow($base, $exponent, $this->optionalScale($frame, 2));
    }
}

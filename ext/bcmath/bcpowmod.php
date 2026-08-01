<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;

/** bcpowmod() — modular exponentiation (php-src ext/bcmath/bcmath.c; issue #6976). */
final class bcpowmod extends BcmathFunction
{
    public function __construct()
    {
        parent::__construct('bcpowmod');
    }

    protected function compute(Frame $frame): string
    {
        $this->requireTernaryArgCount($frame);
        $base = $this->requireStringArg($frame, 0, 'num');
        $exponent = $this->requireStringArg($frame, 1, 'exponent');
        $modulus = $this->requireStringArg($frame, 2, 'modulus');

        return VmBcmath::powmod(
            $base,
            $exponent,
            $modulus,
            $this->optionalScale($frame, 3)
        );
    }
}

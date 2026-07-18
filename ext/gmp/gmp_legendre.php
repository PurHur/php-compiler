<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_legendre() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_legendre extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_legendre');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_legendre');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_legendre() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_legendre', 0, 'num1');
        $b = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_legendre', 1, 'num2');

        return VmGmp::legendre($a, $b);
    }
}
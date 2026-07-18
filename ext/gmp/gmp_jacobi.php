<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_jacobi() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_jacobi extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_jacobi');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_jacobi');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_jacobi() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_jacobi', 0, 'num1');
        $b = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_jacobi', 1, 'num2');

        return VmGmp::jacobi($a, $b);
    }
}
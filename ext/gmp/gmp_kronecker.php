<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_kronecker() — php-src ext/gmp/gmp.c; issue #20586. */
final class gmp_kronecker extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_kronecker');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_kronecker');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_kronecker() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_kronecker', 0, 'num1');
        $b = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_kronecker', 1, 'num2');

        return VmGmp::kronecker($a, $b);
    }
}

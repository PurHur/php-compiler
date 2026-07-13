<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_cmp() — compare GMP integers (php-src ext/gmp/gmp.c; issue #3341). */
final class gmp_cmp extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_cmp');
    }

    protected function compute(Frame $frame): int
    {
        VmGmp::requireAvailable('gmp_cmp');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_cmp() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $left = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_cmp', 0, 'num1');
        $right = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_cmp', 1, 'num2');

        return VmGmp::cmp($left, $right);
    }
}

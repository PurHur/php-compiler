<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_hamdist() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_hamdist extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_hamdist');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_hamdist');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_hamdist() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_hamdist', 0, 'num1');
        $b = VmGmp::coerceGmpOperand($frame->calledArgs[1], 'gmp_hamdist', 1, 'num2');

        return VmGmp::hamdist($a, $b);
    }
}
<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_popcount() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_popcount extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_popcount');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_popcount');
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_popcount() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_popcount', 0, 'num');

        return VmGmp::popcount($a);
    }
}
<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_perfect_power() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_perfect_power extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_perfect_power');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_perfect_power');
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_perfect_power() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_perfect_power', 0, 'num');

        return VmGmp::perfectPower($a);
    }
}
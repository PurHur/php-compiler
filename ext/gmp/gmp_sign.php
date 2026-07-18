<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_sign() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_sign extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_sign');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_sign');
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_sign() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_sign', 0, 'num');

        return VmGmp::sign($a);
    }
}
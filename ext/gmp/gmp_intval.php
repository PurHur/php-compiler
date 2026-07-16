<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_intval() — convert GMP to PHP int (php-src ext/gmp/gmp.c; issue #19527). */
final class gmp_intval extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_intval');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_intval');
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_intval() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $num = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_intval', 0, 'gmpnumber');

        return VmGmp::toInt($num);
    }
}

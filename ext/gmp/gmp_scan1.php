<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_scan1() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_scan1 extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_scan1');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_scan1');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_scan1() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_scan1', 0, 'a');
        $start = VmGmp::coerceBitIndex($frame->calledArgs[1], 'gmp_scan1', 1, 'start');

        return VmGmp::scan1($a, $start);
    }
}
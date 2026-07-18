<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_testbit() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_testbit extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_testbit');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_testbit');
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_testbit() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_testbit', 0, 'num');
        $index = VmGmp::coerceBitIndex($frame->calledArgs[1], 'gmp_testbit', 1, 'index');

        return VmGmp::testbit($a, $index);
    }
}
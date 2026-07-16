<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** gmp_random_seed() — php-src ext/gmp/gmp.c; issue #19540. */
final class gmp_random_seed extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_random_seed');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_random_seed');

        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_random_seed() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $seed = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_random_seed', 0, 'seed');
        VmGmp::randomSeed($seed);

        return true;

    }
}

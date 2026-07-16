<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_perfect_square() — php-src ext/gmp/gmp.c; issue #19539. */
final class gmp_perfect_square extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_perfect_square');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_perfect_square');
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gmp_perfect_square() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_perfect_square', 0, 'a');

        return VmGmp::perfectSquare($a);
    }
}

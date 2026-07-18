<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;

/** gmp_prob_prime() — php-src ext/gmp/gmp.c; issue #20394. */
final class gmp_prob_prime extends GmpFunction
{
    public function __construct()
    {
        parent::__construct('gmp_prob_prime');
    }

    protected function compute(Frame $frame)
    {
        VmGmp::requireAvailable('gmp_prob_prime');
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'gmp_prob_prime() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        $a = VmGmp::coerceGmpOperand($frame->calledArgs[0], 'gmp_prob_prime', 0, 'num');
        $reps = 10;
        if (2 === $argc) {
            $reps = VmGmp::coerceRepetitions($frame->calledArgs[1]);
        }

        return VmGmp::probPrime($a, $reps);
    }
}
<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_gen_exponential() — PECL RANLIB (#29622). */
final class stats_rand_gen_exponential extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_gen_exponential');
    }

    protected function compute(Frame $frame): float|int|bool|array
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_gen_exponential() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $av = $this->requireFloatArg($frame, 0, 'av');

        return VmStatsRand::genExponential($av, $frame);
    }
}

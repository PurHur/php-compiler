<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_gen_funiform() — PECL RANLIB (#29649). */
final class stats_rand_gen_funiform extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_gen_funiform');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_gen_funiform() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $low = $this->requireFloatArg($frame, 0, 'low');
        $high = $this->requireFloatArg($frame, 1, 'high');

        return VmStatsRand::genFuniform($low, $high, $frame);
    }
}

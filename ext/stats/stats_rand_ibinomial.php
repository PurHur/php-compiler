<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_ibinomial() — PECL RANLIB BTPE (#29649). */
final class stats_rand_ibinomial extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_ibinomial');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_ibinomial() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $n = $this->requireIntArg($frame, 0, 'n');
        $pp = $this->requireFloatArg($frame, 1, 'pp');

        return VmStatsRand::ibinomial($n, $pp, $frame);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_gen_ipoisson() — PECL RANLIB ignpoi (#29684). */
final class stats_rand_gen_ipoisson extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_gen_ipoisson');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_gen_ipoisson() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $mu = $this->requireFloatArg($frame, 0, 'mu');

        return VmStatsRand::genIpoisson($mu, $frame);
    }
}

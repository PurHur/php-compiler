<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_ranf() — PECL RANLIB (#29589). */
final class stats_rand_ranf extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_ranf');
    }

    protected function compute(Frame $frame): float|int|bool|array
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_ranf() expects exactly 0 arguments, '.$argc.' given'
            );
        }

        return VmStatsRand::ranf();
    }
}

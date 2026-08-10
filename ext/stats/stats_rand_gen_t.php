<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_gen_t() — PECL RANLIB snorm/genchi composition (#29684). */
final class stats_rand_gen_t extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_gen_t');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_gen_t() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $df = $this->requireFloatArg($frame, 0, 'df');

        return VmStatsRand::genT($df, $frame);
    }
}

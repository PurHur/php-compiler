<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_gen_normal() — PECL RANLIB (#29589). */
final class stats_rand_gen_normal extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_gen_normal');
    }

    protected function compute(Frame $frame): float|int|bool|array
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_gen_normal() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $av = $this->requireFloatArg($frame, 0, 'av');
        $sd = $this->requireFloatArg($frame, 1, 'sd');

        return VmStatsRand::genNormal($av, $sd, $frame);
    }
}

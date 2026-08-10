<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_gen_iuniform() — PECL RANLIB (#29589). */
final class stats_rand_gen_iuniform extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_gen_iuniform');
    }

    protected function compute(Frame $frame): float|int|bool|array
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_gen_iuniform() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $low = $this->requireIntArg($frame, 0, 'low');
        $high = $this->requireIntArg($frame, 1, 'high');

        return VmStatsRand::genIuniform($low, $high, $frame);
    }
}

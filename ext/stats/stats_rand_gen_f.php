<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_gen_f() — PECL RANLIB (#29649). */
final class stats_rand_gen_f extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_gen_f');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_gen_f() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $dfn = $this->requireFloatArg($frame, 0, 'dfn');
        $dfd = $this->requireFloatArg($frame, 1, 'dfd');

        return VmStatsRand::genF($dfn, $dfd, $frame);
    }
}

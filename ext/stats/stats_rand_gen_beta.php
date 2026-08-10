<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_gen_beta() — PECL RANLIB (#29622). */
final class stats_rand_gen_beta extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_gen_beta');
    }

    protected function compute(Frame $frame): float|int|bool|array
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_gen_beta() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $a = $this->requireFloatArg($frame, 0, 'a');
        $b = $this->requireFloatArg($frame, 1, 'b');

        return VmStatsRand::genBeta($a, $b, $frame);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_ibinomial_negative() — PECL RANLIB ignnbn (#29684). */
final class stats_rand_ibinomial_negative extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_ibinomial_negative');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_ibinomial_negative() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $n = $this->requireIntArg($frame, 0, 'n');
        $p = $this->requireFloatArg($frame, 1, 'p');

        return VmStatsRand::ibinomialNegative($n, $p, $frame);
    }
}

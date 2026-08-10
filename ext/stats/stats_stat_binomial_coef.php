<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_stat_binomial_coef() — PECL stats (#28080). */
final class stats_stat_binomial_coef extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_stat_binomial_coef');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_stat_binomial_coef() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $x = $this->requireIntArg($frame, 0, 'x');
        $n = $this->requireIntArg($frame, 1, 'n');

        return VmStats::binomialCoef($x, $n);
    }
}

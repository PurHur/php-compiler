<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_dens_pmf_poisson() — PECL stats dens (#29587). */
final class stats_dens_pmf_poisson extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_dens_pmf_poisson');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_dens_pmf_poisson() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $x = $this->requireFloatArg($frame, 0, 'x');
        $lb = $this->requireFloatArg($frame, 1, 'lb');

        return VmStatsDens::dispatch(
            VmStatsDens::OP_PMF_POISSON,
            $x, $lb, 0.0, 0.0,
            $frame
        );
    }
}

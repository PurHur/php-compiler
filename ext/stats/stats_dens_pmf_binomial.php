<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_dens_pmf_binomial() — PECL stats dens (#29587). */
final class stats_dens_pmf_binomial extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_dens_pmf_binomial');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'stats_dens_pmf_binomial() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        $x = $this->requireFloatArg($frame, 0, 'x');
        $n = $this->requireFloatArg($frame, 1, 'n');
        $pi = $this->requireFloatArg($frame, 2, 'pi');

        return VmStatsDens::dispatch(
            VmStatsDens::OP_PMF_BINOMIAL,
            $x, $n, $pi, 0.0,
            $frame
        );
    }
}

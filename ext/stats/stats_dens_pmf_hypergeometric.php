<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_dens_pmf_hypergeometric() — PECL stats dens (#29587). */
final class stats_dens_pmf_hypergeometric extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_dens_pmf_hypergeometric');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (4 !== $argc) {
            throw new \ArgumentCountError(
                'stats_dens_pmf_hypergeometric() expects exactly 4 arguments, '.$argc.' given'
            );
        }
        $n1 = $this->requireFloatArg($frame, 0, 'n1');
        $n2 = $this->requireFloatArg($frame, 1, 'n2');
        $N1 = $this->requireFloatArg($frame, 2, 'N1');
        $N2 = $this->requireFloatArg($frame, 3, 'N2');

        return VmStatsDens::dispatch(
            VmStatsDens::OP_PMF_HYPER,
            $n1, $n2, $N1, $N2,
            $frame
        );
    }
}

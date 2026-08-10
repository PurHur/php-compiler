<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_cdf_poisson() — PECL stats CDF (#29621). */
final class stats_cdf_poisson extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_cdf_poisson');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'stats_cdf_poisson() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        $par1 = $this->requireFloatArg($frame, 0, 'par1');
        $par2 = $this->requireFloatArg($frame, 1, 'par2');
        $which = $this->requireIntArg($frame, 2, 'which');

        return VmStatsCdf::dispatch(
            VmStatsCdf::OP_POISSON,
            $which,
            $par1,
            $par2,
            0.0,
            $frame
        );
    }
}

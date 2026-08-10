<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_cdf_negative_binomial() — PECL stats CDF (#29683). */
final class stats_cdf_negative_binomial extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_cdf_negative_binomial');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (4 !== $argc) {
            throw new \ArgumentCountError(
                'stats_cdf_negative_binomial() expects exactly 4 arguments, '.$argc.' given'
            );
        }
        $par1 = $this->requireFloatArg($frame, 0, 'par1');
        $par2 = $this->requireFloatArg($frame, 1, 'par2');
        $par3 = $this->requireFloatArg($frame, 2, 'par3');
        $which = $this->requireIntArg($frame, 3, 'which');

        return VmStatsCdf::dispatch(
            VmStatsCdf::OP_NEGATIVE_BINOMIAL,
            $which,
            $par1,
            $par2,
            $par3,
            $frame
        );
    }
}

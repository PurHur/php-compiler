<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_dens_gamma() — PECL stats dens (#29587). */
final class stats_dens_gamma extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_dens_gamma');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'stats_dens_gamma() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        $x = $this->requireFloatArg($frame, 0, 'x');
        $shape = $this->requireFloatArg($frame, 1, 'shape');
        $scale = $this->requireFloatArg($frame, 2, 'scale');

        return VmStatsDens::dispatch(
            VmStatsDens::OP_GAMMA,
            $x, $shape, $scale, 0.0,
            $frame
        );
    }
}

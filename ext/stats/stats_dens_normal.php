<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_dens_normal() — PECL stats dens (#29587). */
final class stats_dens_normal extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_dens_normal');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'stats_dens_normal() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        $x = $this->requireFloatArg($frame, 0, 'x');
        $ave = $this->requireFloatArg($frame, 1, 'ave');
        $stdev = $this->requireFloatArg($frame, 2, 'stdev');

        return VmStatsDens::dispatch(
            VmStatsDens::OP_NORMAL,
            $x, $ave, $stdev, 0.0,
            $frame
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_dens_chisquare() — PECL stats dens (#29587). */
final class stats_dens_chisquare extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_dens_chisquare');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_dens_chisquare() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $x = $this->requireFloatArg($frame, 0, 'x');
        $dfr = $this->requireFloatArg($frame, 1, 'dfr');

        return VmStatsDens::dispatch(
            VmStatsDens::OP_CHISQUARE,
            $x, $dfr, 0.0, 0.0,
            $frame
        );
    }
}

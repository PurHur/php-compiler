<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_dens_f() — PECL stats dens (#29587). */
final class stats_dens_f extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_dens_f');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'stats_dens_f() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        $x = $this->requireFloatArg($frame, 0, 'x');
        $dfr1 = $this->requireFloatArg($frame, 1, 'dfr1');
        $dfr2 = $this->requireFloatArg($frame, 2, 'dfr2');

        return VmStatsDens::dispatch(
            VmStatsDens::OP_F,
            $x, $dfr1, $dfr2, 0.0,
            $frame
        );
    }
}

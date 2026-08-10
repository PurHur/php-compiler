<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_dens_uniform() — PECL stats dens (#29587). */
final class stats_dens_uniform extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_dens_uniform');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'stats_dens_uniform() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        $x = $this->requireFloatArg($frame, 0, 'x');
        $a = $this->requireFloatArg($frame, 1, 'a');
        $b = $this->requireFloatArg($frame, 2, 'b');

        return VmStatsDens::dispatch(
            VmStatsDens::OP_UNIFORM,
            $x, $a, $b, 0.0,
            $frame
        );
    }
}

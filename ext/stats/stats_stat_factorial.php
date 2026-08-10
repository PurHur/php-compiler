<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_stat_factorial() — PECL stats (#28080). */
final class stats_stat_factorial extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_stat_factorial');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'stats_stat_factorial() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $n = $this->requireIntArg($frame, 0, 'n');

        return VmStats::factorial($n);
    }
}

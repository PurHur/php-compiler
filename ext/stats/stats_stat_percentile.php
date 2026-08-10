<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_stat_percentile() — PECL stats (#28080). */
final class stats_stat_percentile extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_stat_percentile');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_stat_percentile() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $array = $this->requireArrayArg($frame, 0, 'arr');
        $perc = $this->requireFloatArg($frame, 1, 'perc');
        $values = VmStats::coerceNumericArray($array->toArray(), $frame);

        return VmStats::percentile($values, $perc, $frame, 'stats_stat_percentile');
    }
}

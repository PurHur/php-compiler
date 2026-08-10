<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_stat_innerproduct() — PECL stats (#28080). */
final class stats_stat_innerproduct extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_stat_innerproduct');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_stat_innerproduct() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $a = $this->requireArrayArg($frame, 0, 'arr1');
        $b = $this->requireArrayArg($frame, 1, 'arr2');
        $valuesA = VmStats::coerceNumericArray($a->toArray(), $frame);
        $valuesB = VmStats::coerceNumericArray($b->toArray(), $frame);

        return VmStats::innerproduct($valuesA, $valuesB, $frame, 'stats_stat_innerproduct');
    }
}

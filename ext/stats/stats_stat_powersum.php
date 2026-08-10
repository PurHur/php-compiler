<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_stat_powersum() — PECL stats (#28080). */
final class stats_stat_powersum extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_stat_powersum');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_stat_powersum() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $array = $this->requireArrayArg($frame, 0, 'arr');
        $power = $this->requireFloatArg($frame, 1, 'power');
        $values = VmStats::coerceNumericArray($array->toArray(), $frame);

        return VmStats::powersum($values, $power, $frame, 'stats_stat_powersum');
    }
}

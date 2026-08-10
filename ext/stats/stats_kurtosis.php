<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_kurtosis() — PECL stats (#28080). */
final class stats_kurtosis extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_kurtosis');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'stats_kurtosis() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $array = $this->requireArrayArg($frame, 0, 'a');
        $values = VmStats::coerceNumericArray($array->toArray(), $frame);

        return VmStats::kurtosis($values, $frame, 'stats_kurtosis');
    }
}

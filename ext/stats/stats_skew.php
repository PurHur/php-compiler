<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_skew() — PECL stats (#28080). */
final class stats_skew extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_skew');
    }

    protected function compute(Frame $frame): float|int|bool
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'stats_skew() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $array = $this->requireArrayArg($frame, 0, 'a');
        $values = VmStats::coerceNumericArray($array->toArray(), $frame);

        return VmStats::skew($values, $frame, 'stats_skew');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_variance() — PECL stats (issue #5748). */
final class stats_variance extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_variance');
    }

    protected function compute(Frame $frame): float|bool
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'stats_variance() expects at least 1 argument, '.\max(0, $argc - 1).' given'
            );
        }
        $array = $this->requireArrayArg($frame, 0, 'a');
        $sample = $this->optionalSampleFlag($frame, 1);
        $values = VmStats::coerceNumericArray($array->toArray(), $frame);

        return VmStats::variance($values, $sample, $frame, 'stats_variance');
    }
}

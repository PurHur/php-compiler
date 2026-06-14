<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_covariance() — PECL stats (issue #5748). */
final class stats_covariance extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_covariance');
    }

    protected function compute(Frame $frame): float|bool
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'stats_covariance() expects at least 2 arguments, '.\max(0, $argc - 2).' given'
            );
        }
        $a = $this->requireArrayArg($frame, 0, 'a');
        $b = $this->requireArrayArg($frame, 1, 'b');
        $sample = $this->optionalSampleFlag($frame, 2);
        $valuesA = VmStats::coerceNumericArray($a->toArray(), $frame);
        $valuesB = VmStats::coerceNumericArray($b->toArray(), $frame);

        return VmStats::covariance($valuesA, $valuesB, $sample, $frame, 'stats_covariance');
    }
}

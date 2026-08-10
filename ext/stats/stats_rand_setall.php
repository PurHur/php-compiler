<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;

/** stats_rand_setall() — PECL RANLIB (#29589). */
final class stats_rand_setall extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_setall');
    }

    protected function compute(Frame $frame): float|int|bool|array
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_setall() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $iseed1 = $this->requireIntArg($frame, 0, 'iseed1');
        $iseed2 = $this->requireIntArg($frame, 1, 'iseed2');

        return VmStatsRand::setall($iseed1, $iseed2);
    }
}

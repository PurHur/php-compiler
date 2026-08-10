<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;

/** stats_rand_getsd() — PECL RANLIB (#29589). */
final class stats_rand_getsd extends StatsFunction
{
    public function __construct()
    {
        parent::__construct('stats_rand_getsd');
    }

    protected function compute(Frame $frame): float|int|bool|array
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(
                'stats_rand_getsd() expects exactly 0 arguments, '.$argc.' given'
            );
        }

        return VmStatsRand::getsd();
    }

    /** @return HashTable for JIT helper path */
    public static function seedsAsHashTable(): HashTable
    {
        return VmStatsRand::getsdHashTable();
    }
}

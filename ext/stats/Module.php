<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\ModuleAbstract;

/**
 * stats extension module entry (PECL stats; issue #5748).
 *
 * Algorithms in {@see VmStats} — PHP-in-PHP, no runtime/*.c.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new stats_standard_deviation(),
            new stats_variance(),
            new stats_covariance(),
        ];
    }
}

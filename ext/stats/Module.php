<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\ModuleAbstract;

/**
 * stats extension module entry (PECL stats; issue #5748, #26743).
 *
 * Algorithms in {@see VmStats} — PHP-in-PHP, no runtime/*.c.
 * Advertise stats_* / extension_loaded('stats') only when
 * {@see StatsExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        if (!StatsExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new stats_standard_deviation(),
            new stats_variance(),
            new stats_covariance(),
        ];
    }
}

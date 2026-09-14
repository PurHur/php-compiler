<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/stats advertisement — PECL stats (#5748, #26743).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * Pure-PHP {@see VmStats} stays compiled in-tree but must not flip
 * {@code extension_loaded('stats')} / {@code function_exists('stats_*')} when host
 * Zend has no pecl-stats — same host-module gate as ds/gnupg (#25086, #25360).
 *
 * Enable via host {@code extension_loaded('stats')}, or explicit
 * {@code PHP_COMPILER_ENABLE_STATS=1} (functional PHPT / local runs).
 */
final class StatsExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('stats');
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise stats_* / extension_loaded('stats'). */
    public static function isStatsComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'stats_standard_deviation')
            || str_contains($testFileName, 'stats_variance')
            || str_contains($testFileName, 'stats_covariance')
            || str_contains($testFileName, 'stats_descriptive')
            || str_contains($testFileName, 'stats_absolute_deviation')
            || str_contains($testFileName, 'stats_harmonic_mean')
            || str_contains($testFileName, 'stats_skew')
            || str_contains($testFileName, 'stats_kurtosis')
            || str_contains($testFileName, 'stats_stat_')
            || str_contains($testFileName, 'stats_dens')
            || str_contains($testFileName, 'stats_cdf')
            || str_contains($testFileName, 'stats_rand')
            || str_contains($testFileName, 'extension_loaded_stats')
            || str_contains($testFileName, 'maintainer_gap_stats')
            || str_contains($testFileName, 'stats_phantom')
            || str_contains($testFileName, 'stats_funcs');
    }

    /** Phantom-registration guards that assert stats is withheld (#26743). */
    public static function isStatsPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'stats_phantom')
            || str_contains($testFileName, 'extension_loaded_stats_phantom')
            || str_contains($testFileName, 'maintainer_gap_stats');
    }

    /**
     * Functional stats cases set {@code PHP_COMPILER_ENABLE_STATS} via {@code --ENV--}; module
     * phantom guards run only when stats is withheld (#26743).
     */
    public static function runsStatsCompliance(string $testFileName): bool
    {
        if (self::isStatsPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\ReleaseUnsupportedExtensions;

/**
 * ext/gmp advertisement — php-src ext/gmp/gmp.c (#3341 / #22860 / #24697).
 *
 * GMP APIs stay in-tree (PHP-in-PHP) but are **release-unsupported** for v1.1.0 (#24697):
 * every executed compliance case failed, so advertising a half-implemented surface yields
 * silent wrong answers. Product default: withhold {@code extension_loaded('gmp')} /
 * {@code function_exists('gmp_*')} / {@code class_exists('GMP')} unless the operator opts
 * in with {@code PHP_COMPILER_ENABLE_GMP=1} (zip/curl experimental shape).
 *
 * Host php-gmp and {@code PHP_COMPILER_PROFILE=8.4} alone must not invent the module for
 * the product surface. Compliance injects the enable flag for functional cases so debt
 * stays visible in the baseline ({@see ReleaseUnsupportedExtensions::applyComplianceEnv()}).
 */
final class GmpExtensionPolicy
{
    /**
     * extension_loaded('gmp') / CREDITS_MODULES — opt-in only for v1.1.0 (#24697).
     */
    public static function advertisesExtension(): bool
    {
        return ReleaseUnsupportedExtensions::explicitEnableRequested(ReleaseUnsupportedExtensions::EXT_GMP);
    }

    /** Compliance filenames that exercise gmp_* / GMP. */
    public static function isGmpComplianceCase(string $testFileName): bool
    {
        return str_starts_with($testFileName, 'gmp/')
            || str_contains($testFileName, 'gmp_')
            || str_contains($testFileName, '/gmp/')
            || str_contains($testFileName, 'extension_loaded_gmp');
    }

    /** Phantom-registration guards that assert gmp is withheld (#22860). */
    public static function isGmpPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'gmp_phantom')
            || str_contains($testFileName, 'extension_loaded_gmp_phantom');
    }

    /**
     * Functional gmp cases always run; {@see ReleaseUnsupportedExtensions::applyComplianceEnv()}
     * injects {@code PHP_COMPILER_ENABLE_GMP} so debt stays measurable (#24697). Phantoms only
     * when the surface is withheld (zip/curl shape).
     */
    public static function runsGmpCompliance(string $testFileName): bool
    {
        if (self::isGmpPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }
}

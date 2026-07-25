<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

/**
 * ext/gmp advertisement — php-src ext/gmp/gmp.c (#3341 / #22860).
 *
 * GMP APIs stay in-tree (PHP-in-PHP) but are withheld from extension_loaded() /
 * function_exists('gmp_*') / class_exists('GMP') on the reference harness when host
 * Zend has no php-gmp — same shape as soap/yaml (#22859 / #6275). Enable via host
 * ext/gmp or `PHP_COMPILER_PROFILE=8.4` ({@see \PHPCompiler\CompilerVersion::supportsGmp()}).
 */
final class GmpExtensionPolicy
{
    /**
     * extension_loaded('gmp') / CREDITS_MODULES — match Zend without phantom gmp (#22860).
     */
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('gmp')) {
            return true;
        }

        return \PHPCompiler\CompilerVersion::supportsGmp();
    }

    /** Compliance filenames that exercise gmp_* / GMP. */
    public static function isGmpComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'gmp_')
            || str_contains($testFileName, '/gmp/')
            || str_contains($testFileName, 'extension_loaded_gmp');
    }

    /** Phantom-registration guards that assert gmp is withheld (#22860). */
    public static function isGmpPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'gmp_phantom')
            || str_contains($testFileName, 'extension_loaded_gmp_phantom');
    }

    /** Run functional gmp compliance when advertised, else phantom only (#22860). */
    public static function runsGmpCompliance(string $testFileName): bool
    {
        if (self::advertisesExtension()) {
            return !self::isGmpPhantomComplianceCase($testFileName);
        }

        return self::isGmpPhantomComplianceCase($testFileName);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

/**
 * ext/zip surface advertisement — php-src ext/zip/php_zip.c (#11676, #18137, #3337, #25010).
 *
 * Pure-PHP ZipArchive ({@see VmZipArchive}) and zip_* procedural API stay compiled in-tree but
 * must not flip {@code extension_loaded('zip')} / {@code class_exists('ZipArchive')} when host
 * Zend has no ext/zip — same host-module gate as pgsql/gd (#24994 / #22740). Forward
 * {@code PHP_COMPILER_PROFILE=8.4} alone must not invent the optional module.
 *
 * Enable via host {@code extension_loaded('zip')}, or explicit {@code PHP_COMPILER_ENABLE_ZIP=1}
 * (functional PHPT / local runs).
 */
final class ZipExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('zip')) {
            return true;
        }

        return self::explicitEnableRequested();
    }

    /** Compliance filenames that exercise ZipArchive / zip_* / extension_loaded('zip'). */
    public static function isZipComplianceCase(string $testFileName): bool
    {
        if (str_starts_with($testFileName, 'zlib/')
            || str_contains($testFileName, 'array_map_null_zip')
            || str_contains($testFileName, 'phar_convert_to_data_zip')
            || str_contains($testFileName, 'phar_data_zip_open')
            || str_contains($testFileName, 'phar_convert_to_executable_zip')) {
            return false;
        }

        return str_contains($testFileName, 'ziparchive')
            || str_contains($testFileName, 'extension_loaded_zip')
            || str_contains($testFileName, 'zip_procedural')
            || str_contains($testFileName, 'zip_entry_')
            || str_contains($testFileName, 'zip_open')
            || str_contains($testFileName, '/zip/')
            || str_starts_with($testFileName, 'zip/');
    }

    /** Phantom-registration guards that assert zip is withheld (#18137 / #25010). */
    public static function isZipModulePhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'extension_loaded_zip_phantom')
            || str_contains($testFileName, 'zip_phantom');
    }

    /**
     * Functional zip cases set {@code PHP_COMPILER_ENABLE_ZIP} via {@code --ENV--}; module
     * phantom guards run only when zip is withheld (#25010).
     */
    public static function runsZipCompliance(string $testFileName): bool
    {
        if (self::isZipModulePhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks ext/zip (#25010). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_ZIP');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}

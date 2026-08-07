<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

/**
 * ext/uuid advertisement — pecl-networking-uuid (#5910 / #22228 / #23962).
 *
 * UUID APIs stay in-tree (PHP-in-PHP) but are withheld from extension_loaded() /
 * function_exists('uuid_*') / UUID_* constants on the reference harness when host
 * Zend has no pecl-uuid — same shape as soap/gmp (#22859 / #22860). Enable via host
 * ext/uuid or `PHP_COMPILER_PROFILE=8.4` ({@see \PHPCompiler\CompilerVersion::supportsUuid()}).
 */
final class UuidExtensionPolicy
{
    /**
     * extension_loaded('uuid') / CREDITS_MODULES — match Zend without phantom uuid (#23962).
     */
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('uuid')) {
            return true;
        }

        return \PHPCompiler\CompilerVersion::supportsUuid();
    }

    /** Compliance filenames that exercise uuid_* / UUID_*. */
    public static function isUuidComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'uuid_')
            || str_contains($testFileName, '/uuid/')
            || str_contains($testFileName, 'extension_loaded_uuid');
    }

    /** Phantom-registration guards that assert uuid is withheld (#23962). */
    public static function isUuidPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'uuid_phantom')
            || str_contains($testFileName, 'extension_loaded_uuid_phantom');
    }

    /** Run functional uuid compliance when advertised, else phantom only (#23962). */
    public static function runsUuidCompliance(string $testFileName): bool
    {
        // *_forward84 cases set PROFILE via --ENV--; always include (#27836).
        if (str_contains($testFileName, 'forward84')) {
            return true;
        }
        if (self::advertisesExtension()) {
            return !self::isUuidPhantomComplianceCase($testFileName);
        }

        return self::isUuidPhantomComplianceCase($testFileName);
    }
}

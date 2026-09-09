<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/uuid advertisement — pecl-networking-uuid (#5910 / #22228 / #23962).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 * Compliance helpers for phantom / gated cases stay here.
 */
final class UuidExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('uuid');
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

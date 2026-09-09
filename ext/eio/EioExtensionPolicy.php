<?php

declare(strict_types=1);

namespace PHPCompiler\ext\eio;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/eio surface advertisement — PECL eio / libeio (#6442).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 * Compliance helpers for phantom / gated cases stay here.
 */
final class EioExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('eio');
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function isEioComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'eio_')
            || str_contains($testFileName, '/eio/')
            || str_starts_with($testFileName, 'eio/');
    }

    public static function isEioModulePhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'eio_phantom');
    }

    public static function runsEioCompliance(string $testFileName): bool
    {
        if (self::isEioModulePhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/ssh2 surface advertisement — PECL ssh2 / libssh2 (#6385).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 * Compliance helpers for phantom / gated cases stay here.
 */
final class Ssh2ExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('ssh2');
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function isSsh2ComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'ssh2_')
            || str_contains($testFileName, '/ssh2/')
            || str_starts_with($testFileName, 'ssh2/');
    }

    public static function isSsh2ModulePhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'ssh2_phantom');
    }

    public static function runsSsh2Compliance(string $testFileName): bool
    {
        if (self::isSsh2ModulePhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }
}

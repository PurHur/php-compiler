<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/rar surface advertisement — PECL rar / RarArchive (#6237).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 * Compliance helpers for phantom / gated cases stay here.
 */
final class RarExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('rar');
    }

    public static function isRarComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'rar_')
            || str_contains($testFileName, '/rar/')
            || str_starts_with($testFileName, 'rar/');
    }

    public static function isRarModulePhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'rar_phantom');
    }

    public static function runsRarCompliance(string $testFileName): bool
    {
        if (self::isRarModulePhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        // Functional rar_* cases set PHP_COMPILER_ENABLE_RAR / PROFILE via --ENV--.
        return true;
    }
}

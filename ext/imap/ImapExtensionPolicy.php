<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/imap surface advertisement — php-src ext/imap (#3663).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 * Compliance helpers for phantom / gated cases stay here.
 */
final class ImapExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('imap');
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function isImapComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'imap_')
            || str_contains($testFileName, '/imap/')
            || str_starts_with($testFileName, 'imap/');
    }

    public static function isImapModulePhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'imap_phantom');
    }

    public static function runsImapCompliance(string $testFileName): bool
    {
        if (self::isImapModulePhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        // Functional imap_* cases set PHP_COMPILER_ENABLE_IMAP / PROFILE via --ENV--.
        return true;
    }
}

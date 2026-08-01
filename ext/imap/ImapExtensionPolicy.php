<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\CompilerVersion;

/**
 * ext/imap surface advertisement — php-src ext/imap (#3663).
 *
 * Pure-PHP mbox engine stays in-tree but is withheld from
 * extension_loaded()/function_exists on the reference profile (Zend 8.2
 * harness typically lacks libc-client / php-imap). Enable via
 * {@see CompilerVersion::supportsImap()} (PROFILE≥8.4) or
 * {@code PHP_COMPILER_ENABLE_IMAP=1}.
 */
final class ImapExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (CompilerVersion::supportsImap()) {
            return true;
        }

        return self::explicitEnableRequested();
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

    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_IMAP');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }
        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}

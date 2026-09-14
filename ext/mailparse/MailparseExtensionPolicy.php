<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/mailparse advertisement — PECL mailparse (#6383, #24908).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * Pure-PHP MIME parser stays compiled in-tree but must not flip
 * {@code extension_loaded('mailparse')} / {@code function_exists('mailparse_msg_create')}
 * when host Zend has no pecl-mailparse — same host-module gate as gnupg (#25360).
 *
 * Enable via host {@code extension_loaded('mailparse')}, or explicit
 * {@code PHP_COMPILER_ENABLE_MAILPARSE=1} (functional PHPT / local runs).
 */
final class MailparseExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('mailparse');
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise mailparse_* / extension_loaded('mailparse'). */
    public static function isMailparseComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'mailparse')
            || str_contains($testFileName, 'extension_loaded_mailparse');
    }

    /** Phantom-registration guards that assert mailparse is withheld (#24908). */
    public static function isMailparsePhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'mailparse_phantom')
            || str_contains($testFileName, 'extension_loaded_mailparse_phantom')
            || str_contains($testFileName, 'maintainer_gap_mailparse_extension_phantom');
    }

    /**
     * Functional mailparse cases set {@code PHP_COMPILER_ENABLE_MAILPARSE} via {@code --ENV--};
     * module phantom guards run only when mailparse is withheld (#24908).
     */
    public static function runsMailparseCompliance(string $testFileName): bool
    {
        if (self::isMailparsePhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

}

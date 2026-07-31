<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

/**
 * ext/mailparse advertisement — PECL mailparse (#6383, #24908).
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
        if (\extension_loaded('mailparse')) {
            return true;
        }

        return self::explicitEnableRequested();
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

    /** Explicit side-load / functional-test opt-in when host Zend lacks pecl-mailparse (#24908). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_MAILPARSE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}

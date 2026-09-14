<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uploadprogress;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/uploadprogress advertisement — PECL uploadprogress (#6386, #26744).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * Pure-PHP builtins stay compiled in-tree but must not flip
 * {@code extension_loaded('uploadprogress')} /
 * {@code function_exists('uploadprogress_get_info'|'uploadprogress_get_contents')}
 * when host Zend has no pecl-uploadprogress — same host-module gate as
 * mailparse (#24908) / gnupg (#25360).
 *
 * Enable via host {@code extension_loaded('uploadprogress')}, or explicit
 * {@code PHP_COMPILER_ENABLE_UPLOADPROGRESS=1} (functional PHPT / local runs).
 */
final class UploadprogressExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('uploadprogress');
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise uploadprogress_* / extension_loaded('uploadprogress'). */
    public static function isUploadprogressComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'uploadprogress')
            || str_contains($testFileName, 'extension_loaded_uploadprogress');
    }

    /** Phantom-registration guards that assert uploadprogress is withheld (#26744). */
    public static function isUploadprogressPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'uploadprogress_phantom')
            || str_contains($testFileName, 'extension_loaded_uploadprogress_phantom')
            || str_contains($testFileName, 'maintainer_gap_uploadprogress_phantom');
    }

    /**
     * Functional uploadprogress cases set {@code PHP_COMPILER_ENABLE_UPLOADPROGRESS} via {@code --ENV--};
     * module phantom guards run only when uploadprogress is withheld (#26744).
     */
    public static function runsUploadprogressCompliance(string $testFileName): bool
    {
        if (self::isUploadprogressPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

}

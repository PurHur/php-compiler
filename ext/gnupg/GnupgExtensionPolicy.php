<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/gnupg advertisement — PECL gnupg / libgpgme via FFI (#6668, #25360).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * In-tree FFI bridge stays compiled but must not flip
 * {@code extension_loaded('gnupg')} / {@code function_exists('gnupg_init')} /
 * {@code class_exists('gnupg')} when host Zend has no pecl-gnupg — same host-module
 * gate as zmq (#23964). Libgpgme presence alone must not advertise.
 *
 * Enable via host {@code extension_loaded('gnupg')}, or explicit
 * {@code PHP_COMPILER_ENABLE_GNUPG=1} (functional PHPT / local runs).
 */
final class GnupgExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('gnupg');
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise gnupg_* / gnupg / extension_loaded('gnupg'). */
    public static function isGnupgComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'gnupg')
            || str_contains($testFileName, 'extension_loaded_gnupg');
    }

    /** Phantom-registration guards that assert gnupg is withheld (#25360). */
    public static function isGnupgPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'gnupg_phantom')
            || str_contains($testFileName, 'extension_loaded_gnupg_phantom')
            || str_contains($testFileName, 'maintainer_gap_gnupg_extension_phantom');
    }

    /**
     * Functional gnupg cases set {@code PHP_COMPILER_ENABLE_GNUPG} via {@code --ENV--}; module
     * phantom guards run only when gnupg is withheld (#25360).
     */
    public static function runsGnupgCompliance(string $testFileName): bool
    {
        if (self::isGnupgPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\CompilerVersion;

/**
 * ext/rar surface advertisement — PECL rar / RarArchive (#6237).
 *
 * Pure-PHP store-method engine stays in-tree but is withheld from
 * extension_loaded()/class_exists on the reference profile (Zend 8.2 has no pecl-rar).
 * Enable via {@see CompilerVersion::supportsRar()} (PROFILE≥8.4) or
 * {@code PHP_COMPILER_ENABLE_RAR=1}.
 */
final class RarExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (CompilerVersion::supportsRar()) {
            return true;
        }

        return self::explicitEnableRequested();
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

    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_RAR');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }
        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}

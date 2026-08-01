<?php

declare(strict_types=1);

namespace PHPCompiler\ext\eio;

use PHPCompiler\CompilerVersion;

/**
 * ext/eio surface advertisement — PECL eio / libeio (#6442).
 *
 * Pure-PHP request queue (sync-on-{@see eio_poll}) stays in-tree but is withheld on the
 * reference profile (Zend 8.2 harness typically lacks pecl-eio). Enable via
 * {@see CompilerVersion::supportsEio()} (PROFILE≥8.4) or {@code PHP_COMPILER_ENABLE_EIO=1}.
 */
final class EioExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (CompilerVersion::supportsEio()) {
            return true;
        }

        return self::explicitEnableRequested();
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

    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_EIO');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }
        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}

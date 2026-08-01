<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

use PHPCompiler\CompilerVersion;

/**
 * ext/ssh2 surface advertisement — PECL ssh2 / libssh2 (#6385).
 *
 * Withheld on the reference profile (Zend 8.2 harness typically lacks pecl-ssh2).
 * Enable via {@see CompilerVersion::supportsSsh2()} (PROFILE≥8.4) or
 * {@code PHP_COMPILER_ENABLE_SSH2=1}.
 */
final class Ssh2ExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (CompilerVersion::supportsSsh2()) {
            return true;
        }

        return self::explicitEnableRequested();
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

    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_SSH2');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }
        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}

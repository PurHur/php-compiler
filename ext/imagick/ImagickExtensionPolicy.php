<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

/**
 * ext/imagick surface advertisement — php-src ext/imagick/imagick.c (#6235).
 *
 * PHP-in-PHP Imagick stays in-tree; advertise when host Zend has pecl-imagick
 * or when {@code PHP_COMPILER_ENABLE_IMAGICK=1} and ImageMagick CLI is reachable.
 */
final class ImagickExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('imagick')) {
            return true;
        }

        if (!VmImagickNative::cliAvailable()) {
            return false;
        }

        return self::explicitEnableRequested();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    public static function isImagickComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'imagick_')
            || str_contains($testFileName, 'extension_loaded_imagick');
    }

    public static function isImagickPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'imagick_phantom')
            || str_contains($testFileName, 'extension_loaded_imagick_phantom');
    }

    public static function runsImagickCompliance(string $testFileName): bool
    {
        if (self::advertisesExtension()) {
            return !self::isImagickPhantomComplianceCase($testFileName);
        }

        return self::isImagickPhantomComplianceCase($testFileName);
    }

    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_IMAGICK');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }
        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}

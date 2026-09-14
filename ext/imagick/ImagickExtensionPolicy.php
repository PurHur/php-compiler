<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/imagick surface advertisement — php-src ext/imagick/imagick.c (#6235).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * PHP-in-PHP Imagick stays in-tree; advertise when host Zend has pecl-imagick
 * or when {@code PHP_COMPILER_ENABLE_IMAGICK=1}.
 */
final class ImagickExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('imagick');
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
}

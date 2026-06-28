<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\ModuleRegistry;

/**
 * ext/gd surface advertisement — php-src ext/gd/gd.c (#11675).
 *
 * imagecreate/GdImage register only when {@see ModuleRegistry::extensionLoaded}('gd') is true (#3496).
 */
final class GdExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ModuleRegistry::extensionLoaded('gd');
    }
}

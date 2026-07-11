<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ext\standard\ModuleRegistry;

/**
 * ext/zip surface advertisement — php-src ext/zip/php_zip.c (#11676).
 *
 * ZipArchive registers only when {@see ModuleRegistry::extensionLoaded}('zip') is true (#3337).
 */
final class ZipExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ModuleRegistry::extensionLoaded('zip');
    }
}

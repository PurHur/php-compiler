<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\CompilerVersion;

/**
 * ext/zip surface advertisement — php-src ext/zip/php_zip.c (#11676, #18137, #3337).
 *
 * Pure-PHP ZipArchive ({@see VmZipArchive}) and zip_* procedural API stay compiled in-tree but
 * are withheld from extension_loaded() and class_exists() on the reference profile until
 * {@see CompilerVersion::supportsZip()}.
 */
final class ZipExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsZip();
    }
}

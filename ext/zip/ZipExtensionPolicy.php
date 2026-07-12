<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

/**
 * ext/zip surface advertisement — php-src ext/zip/php_zip.c (#11676, #3337).
 *
 * Pure-PHP ZipArchive ({@see VmZipArchive}) registers when the zip module loads.
 */
final class ZipExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return class_exists(VmZipArchive::class);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php_ini_loaded_file() / php_ini_scanned_files() for compiled JIT/AOT modules (#11562).
 *
 * VM SSOT: {@see VmIniIntrospection}
 * php-src: ext/standard/ini.c
 */
final class IniIntrospectionJitHelper
{
    /** @return string|null null when php_ini_loaded_file() is false */
    public static function loadedFile(): ?string
    {
        $path = VmIniIntrospection::loadedFile();

        return false === $path ? null : $path;
    }

    /** @return string|null null when php_ini_scanned_files() is false */
    public static function scannedFiles(): ?string
    {
        $paths = VmIniIntrospection::scannedFiles();

        return false === $paths ? null : $paths;
    }
}

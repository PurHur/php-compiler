<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** php_ini_loaded_file() / php_ini_scanned_files() VM helpers (ext/standard/ini.c, #6117). */
final class VmIniIntrospection
{
    private const ENV_LOADED_FILE = 'PHP_COMPILER_INI_LOADED_FILE';

    private const ENV_SCANNED_FILES = 'PHP_COMPILER_INI_SCANNED_FILES';

    public static function loadedFile(): string|false
    {
        $path = self::envString(self::ENV_LOADED_FILE);

        return '' === $path ? false : $path;
    }

    public static function scannedFiles(): string|false
    {
        $paths = self::envString(self::ENV_SCANNED_FILES);

        return '' === $paths ? false : $paths;
    }

    private static function envString(string $name): string
    {
        $val = getenv($name);
        if (false === $val || '' === $val) {
            return '';
        }

        return $val;
    }
}

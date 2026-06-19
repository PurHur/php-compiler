<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** php_ini_loaded_file() / php_ini_scanned_files() VM helpers (ext/standard/ini.c, #6117, #9175). */
final class VmIniIntrospection
{
    private const ENV_LOADED_FILE = 'PHP_COMPILER_INI_LOADED_FILE';

    private const ENV_SCANNED_FILES = 'PHP_COMPILER_INI_SCANNED_FILES';

    /**
     * Mirror host Zend ini introspection into process env before VM/JIT run (#9175).
     * Skips when test harness already set PHP_COMPILER_INI_* overrides.
     */
    public static function seedHostIniEnvFromZend(): void
    {
        if (!\function_exists('putenv') || !\function_exists('getenv')) {
            return;
        }
        if ('' === self::envString(self::ENV_LOADED_FILE) && \function_exists('php_ini_loaded_file')) {
            $loaded = \php_ini_loaded_file();
            if (\is_string($loaded) && '' !== $loaded) {
                \putenv(self::ENV_LOADED_FILE.'='.$loaded);
            }
        }
        if ('' === self::envString(self::ENV_SCANNED_FILES) && \function_exists('php_ini_scanned_files')) {
            $scanned = \php_ini_scanned_files();
            if (\is_string($scanned) && '' !== $scanned) {
                \putenv(self::ENV_SCANNED_FILES.'='.$scanned);
            }
        }
    }

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
        $val = \getenv($name);
        if (false === $val || '' === $val) {
            return '';
        }

        return $val;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** php_ini_loaded_file() / php_ini_scanned_files() VM helpers (ext/standard/ini.c, #6117, #9175). */
final class VmIniIntrospection
{
    private const ENV_LOADED_FILE = 'PHP_COMPILER_INI_LOADED_FILE';

    private const ENV_SCANNED_FILES = 'PHP_COMPILER_INI_SCANNED_FILES';

    /**
     * Registered string ini directives mirrored from host Zend ini_get() (#14187).
     *
     * php-src: ext/standard/ini.c — compile-time / php.ini defaults (extension_dir, sendmail_path, …)
     *
     * @var list<string>
     */
    public const MIRRORED_HOST_INI_KEYS = [
        'extension_dir',
        'sendmail_path',
    ];

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
        foreach (self::MIRRORED_HOST_INI_KEYS as $key) {
            $envName = self::mirroredIniEnvName($key);
            if ('' !== self::envString($envName)) {
                continue;
            }
            if (!\function_exists('ini_get')) {
                continue;
            }
            $host = @\ini_get($key);
            if (\is_string($host)) {
                \putenv($envName.'='.$host);
            }
        }
    }

    /**
     * Host-mirrored ini_get() for registered directives outside VmIni::SUPPORTED_KEYS (#14187).
     *
     * @return string|null null when $key is not mirrored
     */
    public static function mirroredHostIniGet(string $key): ?string
    {
        $normalized = strtolower($key);
        if (!\in_array($normalized, self::MIRRORED_HOST_INI_KEYS, true)) {
            return null;
        }
        $env = self::envString(self::mirroredIniEnvName($normalized));
        if ('' !== $env) {
            return $env;
        }
        if (\function_exists('ini_get')) {
            $host = @\ini_get($normalized);
            if (\is_string($host)) {
                return $host;
            }
        }

        return '';
    }

    private static function mirroredIniEnvName(string $key): string
    {
        return 'PHP_COMPILER_INI_'.strtoupper(str_replace('.', '_', $key));
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
        $val = VmEnv::getenv($name);
        if (false !== $val && '' !== $val) {
            return $val;
        }
        $val = \getenv($name);
        if (false === $val || '' === $val) {
            return '';
        }

        return $val;
    }
}

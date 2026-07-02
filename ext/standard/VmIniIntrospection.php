<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** php_ini_loaded_file() / php_ini_scanned_files() VM helpers (ext/standard/ini.c, #6117, #9175). */
final class VmIniIntrospection
{
    private const ENV_LOADED_FILE = 'PHP_COMPILER_INI_LOADED_FILE';

    private const ENV_SCANNED_FILES = 'PHP_COMPILER_INI_SCANNED_FILES';

    /** JSON map of phpinfo(INFO_GENERAL) label => value rows mirrored from host Zend (#14283). */
    private const ENV_PHPINFO_GENERAL = 'PHP_COMPILER_PHPINFO_GENERAL_JSON';

    /** @var array<string, string>|null */
    private static ?array $phpinfoGeneralRows = null;

    /** Bootstrap snapshot of loaded php.ini path — not updated by runtime putenv() (#15111). */
    private static string|false|null $frozenLoadedFile = null;

    /** Bootstrap snapshot of scanned ini paths — not updated by runtime putenv() (#15111). */
    private static string|false|null $frozenScannedFiles = null;

    private static bool $iniSnapshotFrozen = false;

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
        self::seedHostPhpinfoGeneralEnvFromZend();
        self::freezeIniSnapshot();
    }

    /** Unit tests — reset bootstrap ini snapshot between cases (#15111). */
    public static function resetIniSnapshotForTesting(): void
    {
        self::$iniSnapshotFrozen = false;
        self::$frozenLoadedFile = null;
        self::$frozenScannedFiles = null;
    }

    /**
     * Mirror host phpinfo(INFO_GENERAL) rows for phpinfo() HTML parity (#14283).
     */
    public static function seedHostPhpinfoGeneralEnvFromZend(): void
    {
        if (!\function_exists('putenv') || !\function_exists('phpinfo')) {
            return;
        }
        if ('' !== self::envString(self::ENV_PHPINFO_GENERAL)) {
            return;
        }
        if (!\defined('INFO_GENERAL')) {
            return;
        }
        \ob_start();
        @\phpinfo(\INFO_GENERAL);
        $text = \ob_get_clean();
        if (!\is_string($text) || '' === $text) {
            return;
        }
        $rows = [];
        foreach (\explode("\n", $text) as $line) {
            $line = \rtrim($line);
            if (\preg_match('/^(.+?) => (.*)$/', $line, $matches)) {
                $rows[\trim($matches[1])] = \trim($matches[2]);
            }
        }
        if ([] === $rows) {
            return;
        }
        $encoded = \json_encode($rows, \JSON_UNESCAPED_UNICODE);
        if (\is_string($encoded)) {
            \putenv(self::ENV_PHPINFO_GENERAL.'='.$encoded);
        }
    }

    /** phpinfo(INFO_GENERAL) row value; mirrors host Zend when seeded (#14283). */
    public static function phpinfoGeneralRow(string $label, string $default = ''): string
    {
        $rows = self::phpinfoGeneralRows();

        return $rows[$label] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    private static function phpinfoGeneralRows(): array
    {
        if (null !== self::$phpinfoGeneralRows) {
            return self::$phpinfoGeneralRows;
        }
        $json = self::envString(self::ENV_PHPINFO_GENERAL);
        if ('' !== $json) {
            $decoded = \json_decode($json, true);
            if (\is_array($decoded)) {
                /** @var array<string, string> $decoded */
                self::$phpinfoGeneralRows = $decoded;

                return self::$phpinfoGeneralRows;
            }
        }
        self::$phpinfoGeneralRows = self::defaultPhpinfoGeneralRows();

        return self::$phpinfoGeneralRows;
    }

    /**
     * @return array<string, string>
     */
    private static function defaultPhpinfoGeneralRows(): array
    {
        $loaded = self::loadedFile();
        $scanned = self::scannedFiles();
        $configPath = \defined('PHP_CONFIG_FILE_PATH') ? (string) \PHP_CONFIG_FILE_PATH : '';
        $scanDir = \defined('PHP_CONFIG_FILE_SCAN_DIR') ? (string) \PHP_CONFIG_FILE_SCAN_DIR : '';

        return [
            'Virtual Directory Support' => 'disabled',
            'Configuration File (php.ini) Path' => $configPath,
            'Loaded Configuration File' => \is_string($loaded) ? $loaded : '(none)',
            'Scan this dir for additional .ini files' => $scanDir,
            'Additional .ini files parsed' => \is_string($scanned) ? $scanned : '(none)',
            'PHP API' => '20240924',
            'PHP Extension' => '20240924',
            'Zend Extension' => '420240924',
            'Zend Extension Build' => 'API420240924,NTS',
            'PHP Extension Build' => 'API20240924,NTS',
            'Debug Build' => 'no',
            'Thread Safety' => 'disabled',
        ];
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
        self::ensureIniSnapshotFrozen();

        return self::$frozenLoadedFile ?? false;
    }

    public static function scannedFiles(): string|false
    {
        self::ensureIniSnapshotFrozen();

        return self::$frozenScannedFiles ?? false;
    }

    private static function ensureIniSnapshotFrozen(): void
    {
        if (!self::$iniSnapshotFrozen) {
            self::freezeIniSnapshot();
        }
    }

    /**
     * Capture ini paths at bootstrap — php-src ignores user putenv for these (#15111, ext/standard/ini.c).
     */
    private static function freezeIniSnapshot(): void
    {
        if (self::$iniSnapshotFrozen) {
            return;
        }
        self::$iniSnapshotFrozen = true;
        $loaded = self::bootstrapEnvString(self::ENV_LOADED_FILE);
        self::$frozenLoadedFile = '' === $loaded ? false : $loaded;
        $scanned = self::bootstrapEnvString(self::ENV_SCANNED_FILES);
        self::$frozenScannedFiles = '' === $scanned ? false : $scanned;
    }

    /** Harness/bootstrap environ only — excludes VmEnv putenv() overlay (#15111). */
    private static function bootstrapEnvString(string $name): string
    {
        $val = VmEnvPutenvNative::getenv($name);
        if (false !== $val && '' !== $val) {
            return $val;
        }
        $val = \getenv($name);
        if (false === $val || '' === $val) {
            return '';
        }

        return $val;
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

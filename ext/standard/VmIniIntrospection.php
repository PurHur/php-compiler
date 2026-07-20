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

    /** Base64 phpinfo(INFO_GENERAL) Zend engine credit footer mirrored from host Zend (#16551). */
    private const ENV_PHPINFO_CREDIT_FOOTER = 'PHP_COMPILER_PHPINFO_CREDIT_FOOTER';

    /** JSON snapshot of host ini_get_all() registry (#16433, ext/standard/ini.c). */
    private const ENV_INI_REGISTRY = 'PHP_COMPILER_INI_REGISTRY_JSON';

    /** @var array<string, string>|null */
    private static ?array $phpinfoGeneralRows = null;

    /**
     * Host ini_get_all() registry — keys, per-extension filters, access metadata (#16433).
     *
     * @var array{all: array<string, array{global_value: ?string, local_value: ?string, access: int}>, extensions: array<string, list<string>>}|null|false
     */
    private static array|false|null $iniRegistry = null;

    /** Bootstrap snapshot of loaded php.ini path — not updated by runtime putenv() (#15111). */
    private static string|false|null $frozenLoadedFile = null;

    /** Bootstrap snapshot of scanned ini paths — not updated by runtime putenv() (#15111). */
    private static string|false|null $frozenScannedFiles = null;

    private static bool $iniSnapshotFrozen = false;

    /** CLI -d / PHPT --INI-- overrides for MIRRORED_HOST_INI_KEYS (#3285). */
    /** @var array<string, string> */
    private static array $mirroredOverrides = [];

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
        self::seedHostIniRegistryFromZend();
        self::freezeIniSnapshot();
    }

    /** Unit tests — reset bootstrap ini snapshot between cases (#15111). */
    public static function resetIniSnapshotForTesting(): void
    {
        self::$iniSnapshotFrozen = false;
        self::$frozenLoadedFile = null;
        self::$frozenScannedFiles = null;
        self::$iniRegistry = null;
    }

    /**
     * Mirror host Zend ini_get_all() tables for ini_get_all() parity (#16433).
     */
    public static function seedHostIniRegistryFromZend(): void
    {
        if (!\function_exists('putenv') || !\function_exists('ini_get_all')) {
            return;
        }
        if ('' !== self::envString(self::ENV_INI_REGISTRY)) {
            return;
        }
        $all = @\ini_get_all(null, true);
        if (!\is_array($all) || [] === $all) {
            return;
        }

        $normalized = [];
        foreach ($all as $key => $entry) {
            if (!\is_string($key) || !\is_array($entry)) {
                continue;
            }
            $global = $entry['global_value'] ?? null;
            $local = $entry['local_value'] ?? null;
            $normalized[$key] = [
                'global_value' => null === $global ? null : (string) $global,
                'local_value' => null === $local ? null : (string) $local,
                'access' => (int) ($entry['access'] ?? 7),
            ];
        }
        if ([] === $normalized) {
            return;
        }

        $extensions = ['core' => \array_keys($normalized)];
        if (\function_exists('get_loaded_extensions')) {
            foreach (\get_loaded_extensions() as $ext) {
                if (!\is_string($ext) || '' === $ext) {
                    continue;
                }
                $sub = @\ini_get_all($ext, true);
                if (!\is_array($sub) || [] === $sub) {
                    continue;
                }
                $extensions[\strtolower($ext)] = \array_keys($sub);
            }
        }

        $encoded = \json_encode(
            ['all' => $normalized, 'extensions' => $extensions],
            \JSON_UNESCAPED_UNICODE
        );
        if (\is_string($encoded)) {
            \putenv(self::ENV_INI_REGISTRY.'='.$encoded);
        }
    }

    public static function isKnownIniExtension(string $extension): bool
    {
        return null !== self::registryKeysForExtension($extension);
    }

    /**
     * @return list<string>|null null when extension is unknown
     */
    public static function registryKeysForExtension(?string $extension): ?array
    {
        $data = self::iniRegistryData();
        if (null === $data) {
            return null;
        }
        if (null === $extension) {
            return \array_keys($data['all']);
        }

        return $data['extensions'][\strtolower($extension)] ?? null;
    }

    /**
     * @return array{global_value: ?string, local_value: ?string, access: int}|null
     */
    public static function registryEntry(string $key): ?array
    {
        $data = self::iniRegistryData();
        if (null === $data) {
            return null;
        }
        if (isset($data['all'][$key])) {
            return $data['all'][$key];
        }
        $lower = \strtolower($key);
        foreach ($data['all'] as $regKey => $entry) {
            if (\strtolower((string) $regKey) === $lower) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array{all: array<string, array{global_value: ?string, local_value: ?string, access: int}>, extensions: array<string, list<string>>}|null
     */
    private static function iniRegistryData(): ?array
    {
        if (false === self::$iniRegistry) {
            return null;
        }
        if (null !== self::$iniRegistry) {
            return self::$iniRegistry;
        }

        $json = self::envString(self::ENV_INI_REGISTRY);
        if ('' === $json) {
            self::$iniRegistry = false;

            return null;
        }
        $decoded = \json_decode($json, true);
        if (
            !\is_array($decoded)
            || !isset($decoded['all'], $decoded['extensions'])
            || !\is_array($decoded['all'])
            || !\is_array($decoded['extensions'])
        ) {
            self::$iniRegistry = false;

            return null;
        }

        /** @var array{all: array<string, array{global_value: string, local_value: string, access: int}>, extensions: array<string, list<string>>} $decoded */
        self::$iniRegistry = $decoded;

        return self::$iniRegistry;
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
        $currentLabel = null;
        foreach (\explode("\n", $text) as $line) {
            $line = \rtrim($line);
            if ('' === $line) {
                continue;
            }
            if (\preg_match('/^(.+?) => (.*)$/', $line, $matches)) {
                $currentLabel = \trim($matches[1]);
                $rows[$currentLabel] = \trim($matches[2]);
                continue;
            }
            if (
                null !== $currentLabel
                && !\str_contains($line, ' => ')
                && !\str_starts_with($line, 'This program makes use')
            ) {
                $continuation = \rtrim($line, ", \t");
                if ('' === $continuation) {
                    continue;
                }
                $rows[$currentLabel] = '' === ($rows[$currentLabel] ?? '')
                    ? $continuation
                    : \rtrim((string) $rows[$currentLabel], ',').",\n".$continuation;
                continue;
            }
            $currentLabel = null;
        }
        if ([] === $rows) {
            return;
        }
        if (\function_exists('php_ini_scanned_files')) {
            $scanned = \php_ini_scanned_files();
            if (\is_string($scanned) && '' !== $scanned) {
                $rows['Additional .ini files parsed'] = $scanned;
            }
        }
        $encoded = \json_encode($rows, \JSON_UNESCAPED_UNICODE);
        if (\is_string($encoded)) {
            \putenv(self::ENV_PHPINFO_GENERAL.'='.$encoded);
        }
        if (\preg_match('/(\nThis program makes use[\s\S]*)$/', $text, $matches)) {
            $footer = \rtrim($matches[1], "\r\n");
            if ('' !== $footer) {
                \putenv(self::ENV_PHPINFO_CREDIT_FOOTER.'='.\base64_encode($footer));
            }
        }
    }

    /** phpinfo(INFO_GENERAL) Zend engine credit footer; mirrors host Zend when seeded (#16551). */
    public static function phpinfoCreditFooter(): string
    {
        $encoded = self::envString(self::ENV_PHPINFO_CREDIT_FOOTER);
        if ('' === $encoded) {
            return '';
        }
        $decoded = \base64_decode($encoded, true);
        if (!\is_string($decoded) || '' === $decoded) {
            return '';
        }

        return $decoded;
    }

    /** phpinfo(INFO_GENERAL) row value; mirrors host Zend when seeded (#14283). */
    public static function phpinfoGeneralRow(string $label, string $default = ''): string
    {
        if ('Additional .ini files parsed' === $label) {
            $scanned = self::scannedFiles();
            if (\is_string($scanned) && '' !== $scanned) {
                return $scanned;
            }
        }
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
            'Zend Signal Handling' => 'enabled',
            'Zend Memory Manager' => 'enabled',
            'Zend Multibyte Support' => 'provided by mbstring',
            'Zend Max Execution Timers' => 'disabled',
            'IPv6 Support' => 'enabled',
            'DTrace Support' => 'available, disabled',
            'Registered PHP Streams' => 'php, file, glob, data, http, ftp',
            'Registered Stream Socket Transports' => 'tcp, udp, unix, udg',
            'Registered Stream Filters' => 'string.rot13, string.toupper, string.tolower, convert.*, consumed',
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
        if (\array_key_exists($normalized, self::$mirroredOverrides)) {
            return self::$mirroredOverrides[$normalized];
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

    /**
     * CLI {@code bin/vm.php -d sendmail_path=...} / PHPT --INI-- startup override (#3285).
     */
    public static function setMirroredHostIniOverride(string $key, string $value): void
    {
        $normalized = strtolower($key);
        if (!\in_array($normalized, self::MIRRORED_HOST_INI_KEYS, true)) {
            return;
        }
        self::$mirroredOverrides[$normalized] = $value;
        if (\function_exists('putenv')) {
            \putenv(self::mirroredIniEnvName($normalized).'='.$value);
        }
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

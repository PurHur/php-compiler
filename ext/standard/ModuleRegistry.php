<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\Internal;
use PHPCompiler\Module;

/**
 * Tracks logical extension names for extension_loaded()/get_loaded_extensions() (#7190, #4839)
 * and per-extension builtin lists for get_extension_funcs() (#3433).
 *
 * php-src: ext/standard/info.c — populated when Runtime loads in-tree modules.
 */
final class ModuleRegistry
{
    /** @var list<string> */
    private static array $loaded = [];

    /** @var array<string, list<string>> */
    private static array $extensionFunctions = [];

    /** @var array<string, string> lowercase extension name => module version */
    private static array $extensionVersions = [];

    /** @var array<string, true> lowercase registered internal function names */
    private static array $registeredFunctionLookup = [];

    /**
     * Loaded Zend extensions (php-src zend_llist zend_extensions; #22248).
     * Keyed by exact display name (case-sensitive, matches zend_get_extension).
     *
     * @var array<string, array{name: string, version: string, author: string, url: string, copyright: string}>
     */
    private static array $zendExtensions = [];

    /** @var list<string> */
    private const DATE_EXTENSION_FUNCTIONS = [
        'checkdate',
        'date',
        'getdate',
        'gmdate',
        'gmmktime',
        'gmstrftime',
        'idate',
        'localtime',
        'mktime',
        'strftime',
        'strptime',
        'strtotime',
        'time',
    ];

    public static function reset(): void
    {
        self::$loaded = [];
        self::$extensionFunctions = [];
        self::$extensionVersions = [];
        self::$registeredFunctionLookup = [];
        self::$zendExtensions = [];
    }

    /**
     * Register a Zend extension for get_loaded_extensions(true) / ReflectionZendExtension (#22248).
     *
     * php-src: Zend/zend_extensions.c — zend_register_extension / zend_get_extension
     */
    public static function registerZendExtension(
        string $name,
        string $version,
        string $author,
        string $url,
        string $copyright
    ): void {
        if ('' === $name) {
            return;
        }
        self::$zendExtensions[$name] = [
            'name' => $name,
            'version' => $version,
            'author' => $author,
            'url' => $url,
            'copyright' => $copyright,
        ];
    }

    public static function zendExtensionLoaded(string $name): bool
    {
        return isset(self::$zendExtensions[$name]);
    }

    /**
     * @return array{name: string, version: string, author: string, url: string, copyright: string}|null
     */
    public static function getZendExtension(string $name): ?array
    {
        return self::$zendExtensions[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function getLoadedZendExtensions(): array
    {
        return array_keys(self::$zendExtensions);
    }

    public static function register(string $extensionName, ?string $version = null): void
    {
        $name = strtolower($extensionName);
        if ('' === $name) {
            return;
        }
        if (!\in_array($name, self::$loaded, true)) {
            self::$loaded[] = $name;
        }
        if (null !== $version && '' !== $version) {
            self::$extensionVersions[$name] = $version;
        }
    }

    public static function registerModule(Module $module): void
    {
        self::register('core');
        $moduleVersion = $module->getExtensionVersion();
        $additionalVersions = $module->getAdditionalExtensionVersions();
        $primary = strtolower($module->getExtensionName());
        $withholdOpensslSurface = 'openssl' === $primary
            && !\PHPCompiler\ext\openssl\OpensslExtensionPolicy::advertisesExtension();
        $withholdSqlite3Surface = 'sqlite3' === $primary
            && !\PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy::advertisesExtension();
        $registerSqlite3ExtensionLoaded = 'sqlite3' === $primary
            && \PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy::advertisesExtensionLoaded();
        $withholdLdapSurface = 'ldap' === $primary
            && !\PHPCompiler\ext\ldap\LdapExtensionPolicy::advertisesExtension();
        $withholdInotifySurface = 'inotify' === $primary
            && !\PHPCompiler\ext\inotify\InotifyExtensionPolicy::advertisesExtension();
        $withholdXslSurface = 'xsl' === $primary
            && !\PHPCompiler\ext\xsl\XslExtensionPolicy::advertisesExtension();
        $withholdXmlrpcSurface = 'xmlrpc' === $primary
            && !\PHPCompiler\ext\xmlrpc\XmlrpcExtensionPolicy::advertisesExtension();
        $withholdWddxSurface = 'wddx' === $primary
            && !\PHPCompiler\ext\wddx\WddxExtensionPolicy::advertisesExtension();
        $withholdYamlSurface = 'yaml' === $primary
            && !\PHPCompiler\ext\yaml\YamlExtensionPolicy::advertisesExtension();
        $withholdRedisSurface = 'redis' === $primary
            && !\PHPCompiler\ext\redis\RedisExtensionPolicy::advertisesExtension();
        $withholdMemcachedSurface = 'memcached' === $primary
            && !\PHPCompiler\ext\memcached\MemcachedExtensionPolicy::advertisesExtension();
        $withholdRarSurface = 'rar' === $primary
            && !\PHPCompiler\ext\rar\RarExtensionPolicy::advertisesExtension();
        $withholdImapSurface = 'imap' === $primary
            && !\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension();
        $withholdEioSurface = 'eio' === $primary
            && !\PHPCompiler\ext\eio\EioExtensionPolicy::advertisesExtension();
        $withholdSsh2Surface = 'ssh2' === $primary
            && !\PHPCompiler\ext\ssh2\Ssh2ExtensionPolicy::advertisesExtension();
        $withholdMongodbSurface = 'mongodb' === $primary
            && !\PHPCompiler\ext\mongodb\MongodbExtensionPolicy::advertisesExtension();
        $withholdSnmpSurface = 'snmp' === $primary
            && !\PHPCompiler\ext\snmp\SnmpExtensionPolicy::advertisesExtension();
        $withholdFfiSurface = 'ffi' === $primary
            && !\PHPCompiler\ext\ffi\FfiExtensionPolicy::advertisesExtension();
        $withholdSoapSurface = 'soap' === $primary
            && !\PHPCompiler\ext\soap\SoapExtensionPolicy::advertisesExtension();
        $withholdGmpSurface = 'gmp' === $primary
            && !\PHPCompiler\ext\gmp\GmpExtensionPolicy::advertisesExtension();
        $withholdApcuSurface = 'apcu' === $primary
            && !\PHPCompiler\ext\apcu\ApcuExtensionPolicy::advertisesExtension();
        $withholdUuidSurface = 'uuid' === $primary
            && !\PHPCompiler\ext\uuid\UuidExtensionPolicy::advertisesExtension();
        $withholdPspellSurface = 'pspell' === $primary
            && !\PHPCompiler\ext\pspell\PspellExtensionPolicy::advertisesExtension();
        $withholdEnchantSurface = 'enchant' === $primary
            && !\PHPCompiler\ext\enchant\EnchantExtensionPolicy::advertisesExtension();
        $withholdZmqSurface = 'zmq' === $primary
            && !\PHPCompiler\ext\zmq\ZmqExtensionPolicy::advertisesExtension();
        $withholdZstdSurface = 'zstd' === $primary
            && !\PHPCompiler\ext\zstd\ZstdExtensionPolicy::advertisesExtension();
        $withholdLzfSurface = 'lzf' === $primary
            && !\PHPCompiler\ext\lzf\LzfExtensionPolicy::advertisesExtension();
        $withholdLz4Surface = 'lz4' === $primary
            && !\PHPCompiler\ext\lz4\Lz4ExtensionPolicy::advertisesExtension();
        $withholdDsSurface = 'ds' === $primary
            && !\PHPCompiler\ext\ds\DsExtensionPolicy::advertisesExtension();
        $withholdGnupgSurface = 'gnupg' === $primary
            && !\PHPCompiler\ext\gnupg\GnupgExtensionPolicy::advertisesExtension();
        $withholdMailparseSurface = 'mailparse' === $primary
            && !\PHPCompiler\ext\mailparse\MailparseExtensionPolicy::advertisesExtension();
        $withholdDbaSurface = 'dba' === $primary
            && !\PHPCompiler\ext\dba\DbaExtensionPolicy::advertisesExtension();
        $withholdOdbcSurface = 'odbc' === $primary
            && !\PHPCompiler\ext\odbc\OdbcExtensionPolicy::advertisesExtension();
        $withholdMysqliSurface = 'mysqli' === $primary
            && !\PHPCompiler\ext\mysqli\MysqliExtensionPolicy::advertisesExtension();
        $withholdStatsSurface = 'stats' === $primary
            && !\PHPCompiler\ext\stats\StatsExtensionPolicy::advertisesExtension();

        if (!$withholdOpensslSurface && !$withholdSqlite3Surface && !$withholdLdapSurface && !$withholdInotifySurface && !$withholdXslSurface && !$withholdXmlrpcSurface && !$withholdWddxSurface && !$withholdYamlSurface && !$withholdRedisSurface && !$withholdMemcachedSurface && !$withholdRarSurface && !$withholdImapSurface && !$withholdEioSurface && !$withholdSsh2Surface && !$withholdMongodbSurface && !$withholdSnmpSurface && !$withholdFfiSurface && !$withholdSoapSurface && !$withholdGmpSurface && !$withholdApcuSurface && !$withholdUuidSurface && !$withholdPspellSurface && !$withholdEnchantSurface && !$withholdZmqSurface && !$withholdZstdSurface && !$withholdLzfSurface && !$withholdLz4Surface && !$withholdDsSurface && !$withholdGnupgSurface && !$withholdMailparseSurface && !$withholdDbaSurface && !$withholdOdbcSurface && !$withholdMysqliSurface && !$withholdStatsSurface) {
            self::register($module->getExtensionName(), $moduleVersion);
        } elseif ($registerSqlite3ExtensionLoaded) {
            self::register($module->getExtensionName(), $moduleVersion);
        }
        // opcache is both a module and a Zend extension in php-src (ZendAccelerator.c, #22248).
        // Module name is "Zend OPcache" (#24993); keep Zend-extension registration keyed off either form.
        if ('opcache' === $primary || 'zend opcache' === $primary) {
            self::registerZendExtension(
                'Zend OPcache',
                \PHPCompiler\CompilerVersion::reportedPhpVersion(),
                'Zend Technologies',
                'http://www.zend.com/',
                'Copyright (c)'
            );
        }
        $additional = $module->getAdditionalExtensionNames();
        foreach ($additional as $name) {
            $logical = strtolower($name);
            $version = $additionalVersions[$logical] ?? $moduleVersion;
            self::register($name, $version);
        }

        foreach ($module->getFunctions() as $func) {
            if (!$func instanceof Internal) {
                continue;
            }
            $fnName = strtolower($func->getName());
            if ($withholdOpensslSurface || $withholdSqlite3Surface || $withholdLdapSurface || $withholdInotifySurface || $withholdXslSurface || $withholdXmlrpcSurface || $withholdWddxSurface || $withholdYamlSurface || $withholdRedisSurface || $withholdMemcachedSurface || $withholdRarSurface || $withholdImapSurface || $withholdEioSurface || $withholdSsh2Surface || $withholdMongodbSurface || $withholdSnmpSurface || $withholdFfiSurface || $withholdSoapSurface || $withholdGmpSurface || $withholdApcuSurface || $withholdUuidSurface || $withholdPspellSurface || $withholdEnchantSurface || $withholdZmqSurface || $withholdZstdSurface || $withholdLzfSurface || $withholdLz4Surface || $withholdDsSurface || $withholdGnupgSurface || $withholdMailparseSurface || $withholdDbaSurface || $withholdOdbcSurface || $withholdMysqliSurface || $withholdStatsSurface) {
                self::registerBuiltinLookup($fnName);

                continue;
            }
            $logical = self::logicalExtensionForFunction($fnName, $primary, $additional);
            self::registerModuleFunction($logical, $fnName);
            if (CoreExtensionFunctions::isCoreFunction($fnName)) {
                self::registerModuleFunction('core', $fnName);
            }
        }
    }

    public static function extensionLoaded(string $extension): bool
    {
        $ext = strtolower($extension);
        if (!\in_array($ext, self::$loaded, true)) {
            return false;
        }

        return BuiltinIntrospectionPolicy::extensionIsAdvertised($ext);
    }

    /**
     * Built-in extensions statically linked with the engine (php-src zend_get_module_version).
     *
     * @return list<string>
     */
    public static function bundledExtensionNames(): array
    {
        return ['core', 'standard', 'json', 'date', 'pcre', 'zlib'];
    }

    public static function isBundledExtension(string $extension): bool
    {
        return \in_array(strtolower($extension), self::bundledExtensionNames(), true);
    }

    /**
     * php-src zend_get_module_version() — null when extension is not loaded.
     *
     * Bundled extensions report the PHP runtime version via phpversion(), not library
     * versions (e.g. PCRE2 10.44 is phpinfo-only; issue #11162).
     */
    public static function getExtensionVersion(string $extension): ?string
    {
        $ext = strtolower($extension);
        if (!self::extensionLoaded($ext)) {
            return null;
        }
        if (self::isBundledExtension($ext)) {
            return null;
        }

        return self::$extensionVersions[$ext] ?? null;
    }

    /**
     * Library version for bundled extensions (phpinfo/credits), when distinct from runtime.
     */
    public static function getLibraryExtensionVersion(string $extension): ?string
    {
        $ext = strtolower($extension);
        if (!self::extensionLoaded($ext)) {
            return null;
        }

        return self::$extensionVersions[$ext] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function getLoadedExtensions(): array
    {
        return array_values(array_filter(
            self::$loaded,
            static fn (string $name): bool => BuiltinIntrospectionPolicy::extensionIsAdvertised($name)
        ));
    }

    /**
     * @return list<string>|null null when extension is not loaded or has no function table
     *                           (php-src get_extension_funcs — module->functions == NULL → false)
     */
    public static function getExtensionFunctions(string $extension): ?array
    {
        $ext = strtolower($extension);
        if (!self::extensionLoaded($ext)) {
            return null;
        }
        // Class-only modules (phar/ffi) never call registerModuleFunction — match Zend false (#23156).
        if (!isset(self::$extensionFunctions[$ext])) {
            return null;
        }

        return self::$extensionFunctions[$ext];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function extensionFunctionMap(): array
    {
        return self::$extensionFunctions;
    }

    public static function functionRegisteredInBucket(string $functionName, string $extension): bool
    {
        $lc = strtolower($functionName);
        $ext = strtolower($extension);
        $funcs = self::$extensionFunctions[$ext] ?? [];

        return \in_array($lc, array_map('strtolower', $funcs), true);
    }

    /**
     * Registered extension/builtin names for get_defined_functions() internal bucket (#17415).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_defined_functions)
     *
     * @return list<string>
     */
    public static function advertisedInternalFunctionNames(): array
    {
        $names = [];
        $seen = [];
        foreach (self::$extensionFunctions as $funcs) {
            foreach ($funcs as $name) {
                $lc = strtolower($name);
                if (isset($seen[$lc])) {
                    continue;
                }
                $seen[$lc] = true;
                $names[] = $name;
            }
        }

        return $names;
    }

    public static function isRegisteredBuiltinFunction(string $functionName): bool
    {
        return isset(self::$registeredFunctionLookup[strtolower($functionName)]);
    }

    private static function registerBuiltinLookup(string $functionName): void
    {
        self::$registeredFunctionLookup[strtolower($functionName)] = true;
    }

    private static function registerModuleFunction(string $extension, string $functionName): void
    {
        $ext = strtolower($extension);
        $fnLc = strtolower($functionName);
        self::$registeredFunctionLookup[$fnLc] = true;
        if (!isset(self::$extensionFunctions[$ext])) {
            self::$extensionFunctions[$ext] = [];
        }
        if (!\in_array($functionName, self::$extensionFunctions[$ext], true)) {
            self::$extensionFunctions[$ext][] = $functionName;
        }
    }

    /**
     * @param list<string> $additionalExtensions
     */
    private static function logicalExtensionForFunction(
        string $functionName,
        string $primaryExtension,
        array $additionalExtensions
    ): string {
        // Modules that shell under {@see standard} with one logical owner (ftp/intl/pgsql)
        // attribute every registered builtin to that owner (#23156).
        if ('standard' === $primaryExtension && 1 === \count($additionalExtensions)) {
            return strtolower($additionalExtensions[0]);
        }
        foreach ($additionalExtensions as $name) {
            if (self::functionBelongsToLogicalExtension($functionName, $name)) {
                return strtolower($name);
            }
        }

        return $primaryExtension;
    }

    private static function functionBelongsToLogicalExtension(string $functionName, string $extension): bool
    {
        if ('date' === strtolower($extension)) {
            $lc = strtolower($functionName);

            return str_starts_with($lc, 'date_')
                || str_starts_with($lc, 'timezone_')
                || \in_array($lc, self::DATE_EXTENSION_FUNCTIONS, true);
        }

        return self::functionBelongsToReflectionExtension($functionName, $extension);
    }

    /**
     * Owning extension for reflection / get_extension_funcs parity (php-src zend_module_entry, #18357).
     */
    public static function reflectionOwningExtension(string $functionName): string
    {
        $lc = strtolower($functionName);
        if (CoreExtensionFunctions::isCoreFunction($lc)) {
            return 'core';
        }
        if (\in_array($lc, self::REFLECTION_STANDARD_TYPE_PREDICATES, true)) {
            return 'standard';
        }
        if (\in_array($lc, self::REFLECTION_STANDARD_DATE_FUNCTIONS, true)) {
            return 'standard';
        }
        $owners = [];
        foreach (self::$extensionFunctions as $ext => $funcs) {
            foreach ($funcs as $name) {
                if ($lc === strtolower($name)) {
                    $owners[] = $ext;
                    break;
                }
            }
        }
        $owners = array_values(array_unique($owners));
        if (1 === \count($owners) && 'standard' !== $owners[0]) {
            return $owners[0];
        }
        foreach (self::REFLECTION_EXTENSION_ORDER as $extension) {
            if (self::functionBelongsToReflectionExtension($lc, $extension)) {
                return $extension;
            }
        }

        return 'standard';
    }

    /** @var list<string> lowercase is_* predicates Zend lists under standard, not Core */
    private const REFLECTION_STANDARD_TYPE_PREDICATES = [
        'is_array',
        'is_bool',
        'is_double',
        'is_float',
        'is_int',
        'is_integer',
        'is_long',
        'is_null',
        'is_object',
        'is_string',
    ];

    /** @var list<string> lowercase date builtins Zend lists under standard reflection */
    private const REFLECTION_STANDARD_DATE_FUNCTIONS = [
        'strptime',
    ];

    /** @var list<string> */
    private const REFLECTION_EXTENSION_ORDER = [
        'random',
        'hash',
        'spl',
        'sockets',
        'openssl',
        'curl',
        'json',
        'date',
        'pcre',
        'zlib',
        'readline',
        'bcmath',
        'zip',
        'exif',
        'fileinfo',
        'ftp',
        'pgsql',
        'intl',
    ];

    private static function functionBelongsToReflectionExtension(string $functionName, string $extension): bool
    {
        $lc = strtolower($functionName);

        return match (strtolower($extension)) {
            'random' => \in_array($lc, [
                'lcg_value',
                'mt_srand',
                'srand',
                'rand',
                'mt_rand',
                'mt_getrandmax',
                'getrandmax',
                'random_bytes',
                'random_int',
            ], true),
            'hash' => 'hash' === $lc
                || str_starts_with($lc, 'hash_')
                || str_starts_with($lc, 'mhash'),
            'spl' => str_starts_with($lc, 'spl_')
                || \in_array($lc, [
                    'class_implements',
                    'class_parents',
                    'class_uses',
                    'iterator_apply',
                    'iterator_count',
                    'iterator_to_array',
                ], true),
            'sockets' => str_starts_with($lc, 'socket_')
                && !\in_array($lc, [
                    'socket_set_blocking',
                    'socket_get_status',
                    'socket_set_timeout',
                ], true),
            'json' => str_starts_with($lc, 'json_'),
            'date' => str_starts_with($lc, 'date_')
                || str_starts_with($lc, 'timezone_'),
            'pcre' => str_starts_with($lc, 'preg_'),
            'zlib' => str_starts_with($lc, 'gz')
                || str_starts_with($lc, 'zlib_')
                || str_starts_with($lc, 'deflate_')
                || str_starts_with($lc, 'inflate_')
                || 'readgzfile' === $lc
                || 'ob_gzhandler' === $lc,
            'curl' => str_starts_with($lc, 'curl_'),
            'readline' => str_starts_with($lc, 'readline'),
            'bcmath' => str_starts_with($lc, 'bc'),
            'openssl' => str_starts_with($lc, 'openssl_'),
            'zip' => str_starts_with($lc, 'zip_'),
            'exif' => str_starts_with($lc, 'exif_'),
            'fileinfo' => 'mime_content_type' === $lc,
            // php-src ext/shmop/shmop.c — separate from sysvshm (#22426)
            'shmop' => str_starts_with($lc, 'shmop_'),
            // php-src ext/ftp/php_ftp.c (#23156)
            'ftp' => str_starts_with($lc, 'ftp_'),
            // php-src ext/pgsql/pgsql.c (#23156)
            'pgsql' => str_starts_with($lc, 'pg_'),
            // php-src ext/intl/php_intl.c (#23156)
            'intl' => str_starts_with($lc, 'intl_')
                || str_starts_with($lc, 'collator_')
                || str_starts_with($lc, 'numfmt_')
                || str_starts_with($lc, 'msgfmt_')
                || str_starts_with($lc, 'datefmt_')
                || str_starts_with($lc, 'intlcal_')
                || str_starts_with($lc, 'intltz_')
                || str_starts_with($lc, 'transliterator_')
                || str_starts_with($lc, 'resourcebundle_')
                || str_starts_with($lc, 'normalizer_')
                || str_starts_with($lc, 'grapheme_')
                || str_starts_with($lc, 'idn_')
                || str_starts_with($lc, 'locale_')
                || str_starts_with($lc, 'spoofchecker_'),
            default => false,
        };
    }
}

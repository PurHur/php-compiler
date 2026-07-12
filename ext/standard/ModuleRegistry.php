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

    /** @var list<string> */
    private const DATE_EXTENSION_FUNCTIONS = [
        'checkdate',
        'date',
        'getdate',
        'gmdate',
        'gmmktime',
        'gmgetdate',
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
        $withholdLdapSurface = 'ldap' === $primary
            && !\PHPCompiler\ext\ldap\LdapExtensionPolicy::advertisesExtension();
        $withholdInotifySurface = 'inotify' === $primary
            && !\PHPCompiler\ext\inotify\InotifyExtensionPolicy::advertisesExtension();

        if (!$withholdOpensslSurface && !$withholdSqlite3Surface && !$withholdLdapSurface && !$withholdInotifySurface) {
            self::register($module->getExtensionName(), $moduleVersion);
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
            if ($withholdOpensslSurface || $withholdSqlite3Surface || $withholdLdapSurface || $withholdInotifySurface) {
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
     * @return list<string>|null null when extension is not loaded (php-src get_extension_funcs)
     */
    public static function getExtensionFunctions(string $extension): ?array
    {
        $ext = strtolower($extension);
        if (!self::extensionLoaded($ext)) {
            return null;
        }

        return self::$extensionFunctions[$ext] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function extensionFunctionMap(): array
    {
        return self::$extensionFunctions;
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
        foreach ($additionalExtensions as $name) {
            if (self::functionBelongsToLogicalExtension($functionName, $name)) {
                return strtolower($name);
            }
        }

        return $primaryExtension;
    }

    private static function functionBelongsToLogicalExtension(string $functionName, string $extension): bool
    {
        return match (strtolower($extension)) {
            'json' => str_starts_with($functionName, 'json_'),
            'date' => str_starts_with($functionName, 'date_')
                || str_starts_with($functionName, 'timezone_')
                || \in_array($functionName, self::DATE_EXTENSION_FUNCTIONS, true),
            'pcre' => str_starts_with($functionName, 'preg_'),
            'zlib' => str_starts_with($functionName, 'gz')
                || str_starts_with($functionName, 'zlib_')
                || 'readgzfile' === $functionName,
            'readline' => str_starts_with($functionName, 'readline'),
            'bcmath' => str_starts_with($functionName, 'bc'),
            'openssl' => str_starts_with($functionName, 'openssl_'),
            'zip' => str_starts_with($functionName, 'zip_'),
            default => false,
        };
    }
}

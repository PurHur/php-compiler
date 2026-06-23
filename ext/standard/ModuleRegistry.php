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
        $moduleVersion = $module->getExtensionVersion();
        $additionalVersions = $module->getAdditionalExtensionVersions();
        self::register($module->getExtensionName(), $moduleVersion);
        $additional = $module->getAdditionalExtensionNames();
        foreach ($additional as $name) {
            $logical = strtolower($name);
            $version = $additionalVersions[$logical] ?? $moduleVersion;
            self::register($name, $version);
        }

        $primary = strtolower($module->getExtensionName());
        foreach ($module->getFunctions() as $func) {
            if (!$func instanceof Internal) {
                continue;
            }
            $fnName = strtolower($func->getName());
            $logical = self::logicalExtensionForFunction($fnName, $primary, $additional);
            self::registerModuleFunction($logical, $fnName);
        }
    }

    public static function extensionLoaded(string $extension): bool
    {
        return \in_array(strtolower($extension), self::$loaded, true);
    }

    /**
     * php-src zend_get_module_version() — null when extension is not loaded.
     */
    public static function getExtensionVersion(string $extension): ?string
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
        return self::$loaded;
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

    private static function registerModuleFunction(string $extension, string $functionName): void
    {
        $ext = strtolower($extension);
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
            default => false,
        };
    }
}

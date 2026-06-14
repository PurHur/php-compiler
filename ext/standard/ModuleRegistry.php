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
    }

    public static function register(string $extensionName): void
    {
        $name = strtolower($extensionName);
        if ('' === $name) {
            return;
        }
        if (\in_array($name, self::$loaded, true)) {
            return;
        }
        self::$loaded[] = $name;
    }

    public static function registerModule(Module $module): void
    {
        self::register($module->getExtensionName());
        $additional = $module->getAdditionalExtensionNames();
        foreach ($additional as $name) {
            self::register($name);
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
            default => false,
        };
    }
}

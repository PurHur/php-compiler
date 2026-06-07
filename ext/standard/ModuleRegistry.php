<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Module;

/**
 * Tracks logical extension names for extension_loaded()/get_loaded_extensions() (#7190, #4839).
 *
 * php-src: ext/standard/info.c — populated when Runtime loads in-tree modules.
 */
final class ModuleRegistry
{
    /** @var list<string> */
    private static array $loaded = [];

    public static function reset(): void
    {
        self::$loaded = [];
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
        foreach ($module->getAdditionalExtensionNames() as $name) {
            self::register($name);
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
}

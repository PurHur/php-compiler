<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * phpversion/php_uname/extension introspection for compiled JIT/AOT modules (#9148, php-in-PHP).
 *
 * VM SSOT: {@see VmInfo} / {@see ModuleRegistry}
 * php-src: ext/standard/info.c
 */
final class InfoJitHelper
{
    private static string $extensionFuncsExtension = '';

    private const VERSION_STRING = '8.4.0-dev';

    private const SAPI_STRING = 'cli';

    public static function phpversion(?string $extension): string
    {
        if (null === $extension || '' === $extension) {
            return self::VERSION_STRING;
        }
        if (VmInfo::isEngineExtensionName($extension)) {
            return self::VERSION_STRING;
        }
        if (!ModuleRegistry::extensionLoaded($extension)) {
            return '';
        }

        return ModuleRegistry::getExtensionVersion($extension) ?? self::VERSION_STRING;
    }

    public static function php_sapi_name(): string
    {
        return self::SAPI_STRING;
    }

    public static function zend_version(): string
    {
        return '4.4.0';
    }

    public static function php_uname(?string $mode): string
    {
        $letter = (null === $mode || '' === $mode) ? 'a' : $mode[0];

        return VmUnameNative::php_uname($letter);
    }

    public static function extension_loaded(string $name): bool
    {
        if ('' === $name) {
            return false;
        }

        return ModuleRegistry::extensionLoaded($name);
    }

    public static function countLoadedExtensions(int $zendExtensions): int
    {
        if (0 !== $zendExtensions) {
            return 0;
        }

        return \count(ModuleRegistry::getLoadedExtensions());
    }

    public static function loadedExtensionAt(int $index): string
    {
        return ModuleRegistry::getLoadedExtensions()[$index];
    }

    public static function prepareGetExtensionFuncs(string $name): int
    {
        if ('' === $name) {
            self::$extensionFuncsExtension = '';

            return 0;
        }
        $funcs = ModuleRegistry::getExtensionFunctions($name);
        if (null === $funcs) {
            self::$extensionFuncsExtension = '';

            return 0;
        }
        self::$extensionFuncsExtension = $name;

        return \count($funcs);
    }

    public static function extensionFuncAt(int $index): string
    {
        $funcs = ModuleRegistry::getExtensionFunctions(self::$extensionFuncsExtension);
        if (null === $funcs) {
            return '';
        }

        return $funcs[$index];
    }

    public static function posixUnameAvailable(): int
    {
        return VmUnamePure::available() ? 1 : 0;
    }

    public static function posixUnameField(int $index): string
    {
        $uts = VmUnamePure::utsname();

        return match ($index) {
            0 => $uts['sysname'],
            1 => $uts['nodename'],
            2 => $uts['release'],
            3 => $uts['version'],
            4 => $uts['machine'],
            default => '',
        };
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$extensionFuncsExtension = '';
    }
}

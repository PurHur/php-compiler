<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\HashTable;

/**
 * phpversion/php_uname/extension introspection for compiled JIT/AOT modules (#9148, #13803, php-in-PHP).
 *
 * VM SSOT: {@see VmInfo} / {@see ModuleRegistry}
 * php-src: ext/standard/info.c
 */
final class InfoJitHelper
{
    public static function phpversion(?string $extension): string
    {
        $runtimeVersion = CompilerVersion::reportedPhpVersion();
        if (null === $extension || '' === $extension) {
            return $runtimeVersion;
        }
        if (VmInfo::isEngineExtensionName($extension) || VmInfo::isBundledExtensionName($extension)) {
            return $runtimeVersion;
        }
        if (!ModuleRegistry::extensionLoaded($extension)) {
            return '';
        }

        return ModuleRegistry::getExtensionVersion($extension) ?? $runtimeVersion;
    }

    /** ABI: unknown extension version → null {@see __string__*} (#13803). */
    public static function phpversionArgv(?string $extension): ?string
    {
        if (null === $extension) {
            return CompilerVersion::reportedPhpVersion();
        }
        $v = self::phpversion($extension);

        return '' === $v ? null : $v;
    }

    public static function php_sapi_name(): string
    {
        return CompilerVersion::SAPI;
    }

    public static function zend_version(): string
    {
        return CompilerVersion::zendVersion();
    }

    /**
     * @param string|null $mode null = omitted arg (default "a"); string = full Z_PARAM_STRING
     *                          including empty / multi-char for PROFILE≥8.4 ValueError (#28136)
     */
    public static function php_uname(?string $mode): string
    {
        if (null === $mode) {
            return VmUnameNative::php_uname('a');
        }

        return VmUnameNative::php_uname($mode);
    }

    /**
     * PROFILE≥8.4 entry — assert mode then uname (no getenv; NestedJIT-safe, #28136).
     */
    public static function php_unameStrict(?string $mode): string
    {
        if (null === $mode) {
            return VmUnameNative::php_uname('a');
        }
        VmUnamePure::assertValidMode($mode);

        return VmUnameNative::php_uname($mode);
    }

    public static function extension_loaded(string $name): bool
    {
        if ('' === $name) {
            return false;
        }

        return ModuleRegistry::extensionLoaded($name);
    }

    /** ABI: null/empty name → 0 (#13803). */
    public static function extensionLoadedArgv(?string $name): int
    {
        if (null === $name || '' === $name) {
            return 0;
        }

        return self::extension_loaded($name) ? 1 : 0;
    }

    public static function getLoadedExtensionsArgv(int $zendExtensions): HashTable
    {
        return VmInfo::get_loaded_extensions(0 !== $zendExtensions);
    }

    public static function getExtensionFuncsArgv(?string $extension): ?HashTable
    {
        if (null === $extension) {
            return null;
        }
        $result = VmInfo::get_extension_funcs($extension);

        return false === $result ? null : $result;
    }

    /** Scalar JIT helpers for posix_uname() bridge — HashTable foreach over utsname() SIGSEGV in AOT (#15633). */
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
            5 => $uts['domainname'],
            default => '',
        };
    }

    /** @deprecated LLVM loop helper — use {@see getLoadedExtensionsArgv()} (#13803) */
    public static function countLoadedExtensions(int $zendExtensions): int
    {
        if (0 !== $zendExtensions) {
            return \count(ModuleRegistry::getLoadedZendExtensions());
        }

        return \count(ModuleRegistry::getLoadedExtensions());
    }
}

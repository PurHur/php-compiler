<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

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
        if (null === $extension || '' === $extension) {
            return CompilerVersion::VERSION;
        }
        if (VmInfo::isEngineExtensionName($extension) || VmInfo::isBundledExtensionName($extension)) {
            return CompilerVersion::VERSION;
        }
        if (!ModuleRegistry::extensionLoaded($extension)) {
            return '';
        }

        return ModuleRegistry::getExtensionVersion($extension) ?? CompilerVersion::VERSION;
    }

    /** ABI: unknown extension version → null {@see __string__*} (#13803). */
    public static function phpversionArgv(?string $extension): ?string
    {
        if (null === $extension) {
            return CompilerVersion::VERSION;
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

    public static function posixUnameArgv(): ?HashTable
    {
        if (!VmUnamePure::available()) {
            return null;
        }
        $ht = new HashTable();
        foreach (VmUnamePure::utsname() as $key => $value) {
            $slot = new Variable();
            $slot->string((string) $value);
            $ht->add((string) $key, $slot);
        }

        return $ht;
    }

    /** @deprecated LLVM loop helper — use {@see getLoadedExtensionsArgv()} (#13803) */
    public static function countLoadedExtensions(int $zendExtensions): int
    {
        if (0 !== $zendExtensions) {
            return 0;
        }

        return \count(ModuleRegistry::getLoadedExtensions());
    }
}

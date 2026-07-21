<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

/**
 * ext/pspell advertisement — php-src ext/pspell/pspell.c (#6294).
 *
 * Gate on libaspell FFI so extension_loaded('pspell') matches hosts that can spell-check.
 */
final class PspellExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return VmPspellNative::available();
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }
}

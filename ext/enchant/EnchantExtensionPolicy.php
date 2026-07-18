<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

/**
 * ext/enchant advertisement — php-src ext/enchant/enchant.c (#6230).
 *
 * Gate on libenchant FFI so extension_loaded('enchant') matches hosts that can spell-check.
 */
final class EnchantExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return VmEnchantNative::available();
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

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

/**
 * ext/gnupg advertisement — PECL gnupg / libgpgme via FFI (#6668).
 */
final class GnupgExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return VmGnupgNative::available();
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

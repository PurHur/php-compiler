<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

/**
 * ext/pgsql advertisement — php-src ext/pgsql/pgsql.c (#3741).
 *
 * Gate on libpq FFI so extension_loaded('pgsql') matches builds that can connect.
 */
final class PgsqlExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return VmPgsqlNative::available();
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

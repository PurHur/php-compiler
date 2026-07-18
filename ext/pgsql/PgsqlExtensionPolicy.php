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

    /**
     * PHP 8.4 libpq helpers (pg_jit, pg_put_copy_*, …) — #7083.
     *
     * Gated on compiler VERSION_ID ≥ 80400 (ships with 8.4.0-dev) + libpq FFI.
     */
    public static function advertisesPhp84Helpers(): bool
    {
        return self::advertisesBuiltins()
            && \PHPCompiler\CompilerVersion::VERSION_ID >= 80400;
    }
}

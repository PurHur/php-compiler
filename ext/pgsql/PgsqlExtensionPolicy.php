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
     * PHP 8.4 libpq helpers (pg_jit, pg_put_copy_*, …) — #7083 / #22543.
     *
     * Gated on {@see \PHPCompiler\CompilerVersion::languageProfileVersion()} ≥ 8.4
     * (not bare {@see \PHPCompiler\CompilerVersion::VERSION_ID}) so 8.4.0-dev /
     * {@code PHP_COMPILER_PROFILE=8.2} match Zend 8.2 {@code function_exists} phantoms.
     * Enable via stable 8.4.0+ or explicit {@code PHP_COMPILER_PROFILE=8.4}.
     */
    public static function advertisesPhp84Helpers(): bool
    {
        return self::advertisesBuiltins()
            && version_compare(
                \PHPCompiler\CompilerVersion::languageProfileVersion(),
                '8.4.0',
                '>='
            );
    }
}

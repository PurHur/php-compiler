<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

/**
 * ext/odbc advertisement (php-src ext/odbc/php_odbc.c; #6293).
 *
 * Phase 1 always advertises builtins so function_exists('odbc_connect') is true
 * even when unixODBC FFI is unavailable (connect then fails with a warning).
 */
final class OdbcExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return true;
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

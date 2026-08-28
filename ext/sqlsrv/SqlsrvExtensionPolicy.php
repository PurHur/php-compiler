<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlsrv;

/**
 * ext/sqlsrv surface advertisement — Microsoft sqlsrv (#6577).
 *
 * sqlsrv_* symbols are always registered so {@code function_exists('sqlsrv_connect')}
 * matches enterprise apps that probe before connecting. Live SQL Server I/O requires the
 * Microsoft ODBC driver / host ext/sqlsrv; without it, connect returns false and
 * {@see sqlsrv_errors} reports structured entries (php-src ext/sqlsrv shared/core_conn.cpp).
 */
final class SqlsrvExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return true;
    }

    public static function advertisesBuiltins(): bool
    {
        return true;
    }

    public static function hasNativeDriver(): bool
    {
        return \function_exists('\\sqlsrv_connect');
    }

    public static function isSqlsrvComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'sqlsrv');
    }
}

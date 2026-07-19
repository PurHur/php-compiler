<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Shared odbc_* semantics (php-src ext/odbc/php_odbc.c; #6293).
 */
final class VmOdbcCore
{
    /**
     * @return Variable|false Odbc\Connection or false on failure
     */
    public static function connect(
        string $dsn,
        ?string $user,
        ?string $password,
        int $cursorOpt,
        Context $ctx,
        ?Frame $frame = null,
        string $function = 'odbc_connect'
    ): Variable|false {
        $uid = $user ?? '';
        $pwd = $password ?? '';
        $native = VmOdbcNative::connect($dsn, $uid, $pwd, $cursorOpt);
        if (null === $native) {
            VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
            self::warn(
                $ctx,
                \sprintf(
                    '%s(): SQL error: %s, SQL state %s in SQLConnect',
                    $function,
                    VmOdbcConnection::lastErrorMsg(),
                    VmOdbcConnection::lastState()
                ),
                $frame
            );

            return false;
        }
        VmOdbcConnection::setLastError('', '');

        return VmOdbcConnection::wrap($native, $ctx);
    }

    public static function close(ObjectEntry $connection): bool
    {
        return VmOdbcConnection::close($connection);
    }

    public static function closeAll(): void
    {
        VmOdbcConnection::closeAll();
    }

    /**
     * @return Variable|false
     */
    public static function exec(
        ObjectEntry $connection,
        string $query,
        Context $ctx,
        ?Frame $frame = null
    ): Variable|false {
        if (!VmOdbcConnection::isLive($connection)) {
            throw new \TypeError('odbc_exec(): supplied resource is not a valid ODBC connection resource');
        }
        VmOdbcConnection::setLastError('HY000', 'Failed to fetch error message');
        self::warn(
            $ctx,
            \sprintf(
                'odbc_exec(): SQL error: %s, SQL state %s in SQLExecDirect',
                VmOdbcConnection::lastErrorMsg(),
                VmOdbcConnection::lastState()
            ),
            $frame
        );

        return false;
    }

    private static function warn(Context $ctx, string $message, ?Frame $frame): void
    {
        $ctx->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            null,
            $ctx,
            $frame
        );
    }
}

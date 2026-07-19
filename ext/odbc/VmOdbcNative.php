<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

/**
 * Thin unixODBC FFI bridge (php-src ext/odbc/php_odbc.c; #6293).
 *
 * Best-effort — any FFI / driver failure returns null so Phase 1 connect
 * falls back to Zend-shaped SQLConnect warning + false.
 */
final class VmOdbcNative
{
    public const SQL_SUCCESS = 0;

    public const SQL_SUCCESS_WITH_INFO = 1;

    public const SQL_NTS = -3;

    public const SQL_DRIVER_NOPROMPT = 0;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return array{henv: \FFI\CData, hdbc: \FFI\CData}|null
     */
    public static function connect(string $dsn, string $user, string $password, int $cursorOpt): ?array
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return null;
            }
            $henv = $ffi->new('SQLHENV');
            $rc = (int) $ffi->SQLAllocEnv(\FFI::addr($henv));
            if (!self::ok($rc)) {
                return null;
            }
            $hdbc = $ffi->new('SQLHDBC');
            $rc = (int) $ffi->SQLAllocConnect($henv, \FFI::addr($hdbc));
            if (!self::ok($rc)) {
                @$ffi->SQLFreeEnv($henv);

                return null;
            }
            if (str_contains($dsn, '=')) {
                $out = $ffi->new('char[1024]');
                $outLen = $ffi->new('SQLSMALLINT');
                $rc = (int) $ffi->SQLDriverConnect(
                    $hdbc,
                    null,
                    $dsn,
                    \strlen($dsn),
                    $out,
                    1023,
                    \FFI::addr($outLen),
                    self::SQL_DRIVER_NOPROMPT
                );
            } else {
                $rc = (int) $ffi->SQLConnect(
                    $hdbc,
                    $dsn,
                    self::SQL_NTS,
                    $user,
                    self::SQL_NTS,
                    $password,
                    self::SQL_NTS
                );
            }
            if (!self::ok($rc)) {
                @$ffi->SQLFreeConnect($hdbc);
                @$ffi->SQLFreeEnv($henv);

                return null;
            }

            return ['henv' => $henv, 'hdbc' => $hdbc];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function disconnect(?\FFI\CData $henv, ?\FFI\CData $hdbc): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        try {
            if (null !== $hdbc) {
                @$ffi->SQLDisconnect($hdbc);
                @$ffi->SQLFreeConnect($hdbc);
            }
            if (null !== $henv) {
                @$ffi->SQLFreeEnv($henv);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * @return \FFI|null
     */
    private static function ffi()
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }
        $cdef = <<<'CDEF'
typedef void *SQLHENV;
typedef void *SQLHDBC;
typedef void *SQLHSTMT;
typedef short SQLRETURN;
typedef short SQLSMALLINT;
typedef int SQLINTEGER;
typedef unsigned char SQLCHAR;

SQLRETURN SQLAllocEnv(SQLHENV *phenv);
SQLRETURN SQLAllocConnect(SQLHENV henv, SQLHDBC *phdbc);
SQLRETURN SQLConnect(SQLHDBC hdbc, SQLCHAR *szDSN, SQLSMALLINT cbDSN, SQLCHAR *szUID, SQLSMALLINT cbUID, SQLCHAR *szAuthStr, SQLSMALLINT cbAuthStr);
SQLRETURN SQLDriverConnect(SQLHDBC hdbc, void *hwnd, SQLCHAR *szConnStrIn, SQLSMALLINT cbConnStrIn, SQLCHAR *szConnStrOut, SQLSMALLINT cbConnStrOutMax, SQLSMALLINT *pcbConnStrOut, SQLSMALLINT fDriverCompletion);
SQLRETURN SQLDisconnect(SQLHDBC hdbc);
SQLRETURN SQLFreeConnect(SQLHDBC hdbc);
SQLRETURN SQLFreeEnv(SQLHENV henv);
CDEF;
        foreach (['libodbc.so.2', 'libodbc.so.1', 'libodbc.so', 'libiodbc.so.2', 'libiodbc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable $e) {
                continue;
            }
        }
        self::$ffiUnavailable = true;

        return null;
    }

    private static function ok(int $rc): bool
    {
        return self::SQL_SUCCESS === $rc || self::SQL_SUCCESS_WITH_INFO === $rc;
    }
}

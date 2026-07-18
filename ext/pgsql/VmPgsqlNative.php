<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

/**
 * libpq FFI bridge (php-src ext/pgsql/pgsql.c; #3741).
 */
final class VmPgsqlNative
{
    public const CONNECTION_OK = 0;

    public const PGRES_EMPTY_QUERY = 0;

    public const PGRES_COMMAND_OK = 1;

    public const PGRES_TUPLES_OK = 2;

    public const PGRES_FATAL_ERROR = 7;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return \FFI\CData|null PGconn*
     */
    public static function connect(string $conninfo): ?\FFI\CData
    {
        $ffi = self::requireFfi();
        $conn = $ffi->PQconnectdb($conninfo);
        if (null === $conn) {
            return null;
        }
        if (self::CONNECTION_OK !== (int) $ffi->PQstatus($conn)) {
            // Keep the handle so callers can read PQerrorMessage via lastErrorFromConn,
            // then finish — Zend returns false and sets the connection error string.
            return $conn;
        }

        return $conn;
    }

    public static function status(\FFI\CData $conn): int
    {
        return (int) self::requireFfi()->PQstatus($conn);
    }

    public static function errorMessage(?\FFI\CData $conn): string
    {
        if (null === $conn) {
            return '';
        }
        $msg = self::requireFfi()->PQerrorMessage($conn);

        return self::ffiString($msg);
    }

    public static function finish(\FFI\CData $conn): void
    {
        self::requireFfi()->PQfinish($conn);
    }

    /**
     * @return \FFI\CData|null PGresult*
     */
    public static function exec(\FFI\CData $conn, string $query): ?\FFI\CData
    {
        return self::requireFfi()->PQexec($conn, $query);
    }

    public static function resultStatus(\FFI\CData $result): int
    {
        return (int) self::requireFfi()->PQresultStatus($result);
    }

    public static function ntuples(\FFI\CData $result): int
    {
        return (int) self::requireFfi()->PQntuples($result);
    }

    public static function nfields(\FFI\CData $result): int
    {
        return (int) self::requireFfi()->PQnfields($result);
    }

    public static function fname(\FFI\CData $result, int $fieldNum): string
    {
        return self::ffiString(self::requireFfi()->PQfname($result, $fieldNum));
    }

    public static function getvalue(\FFI\CData $result, int $tupNum, int $fieldNum): string
    {
        return self::ffiString(self::requireFfi()->PQgetvalue($result, $tupNum, $fieldNum));
    }

    public static function getisnull(\FFI\CData $result, int $tupNum, int $fieldNum): bool
    {
        return 1 === (int) self::requireFfi()->PQgetisnull($result, $tupNum, $fieldNum);
    }

    public static function clear(\FFI\CData $result): void
    {
        self::requireFfi()->PQclear($result);
    }

    public static function resultMemorySize(\FFI\CData $result): int
    {
        return (int) self::requireFfi()->PQresultMemorySize($result);
    }

    public static function putCopyData(\FFI\CData $conn, string $buffer): int
    {
        return (int) self::requireFfi()->PQputCopyData($conn, $buffer, \strlen($buffer));
    }

    public static function putCopyEnd(\FFI\CData $conn, ?string $error): int
    {
        return (int) self::requireFfi()->PQputCopyEnd($conn, $error);
    }

    public static function socket(\FFI\CData $conn): int
    {
        return (int) self::requireFfi()->PQsocket($conn);
    }

    public static function escapeIdentifier(\FFI\CData $conn, string $value): string
    {
        $ffi = self::requireFfi();
        $escaped = $ffi->PQescapeIdentifier($conn, $value, \strlen($value));
        $out = self::ffiString($escaped);
        if (null !== $escaped) {
            $ffi->PQfreemem($escaped);
        }

        return $out;
    }

    public static function escapeLiteral(\FFI\CData $conn, string $value): string
    {
        $ffi = self::requireFfi();
        $escaped = $ffi->PQescapeLiteral($conn, $value, \strlen($value));
        $out = self::ffiString($escaped);
        if (null !== $escaped) {
            $ffi->PQfreemem($escaped);
        }

        return $out;
    }

    /** @return \FFI */
    private static function requireFfi()
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \LogicException('pgsql requires libpq FFI (#3741)');
        }

        return $ffi;
    }

    private static function ffiString(mixed $ptr): string
    {
        if (null === $ptr) {
            return '';
        }
        try {
            return \FFI::string($ptr);
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return \FFI|null */
    private static function ffi()
    {
        if (!self::ffiEnabled()) {
            return null;
        }
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
typedef struct pg_conn PGconn;
typedef struct pg_result PGresult;
PGconn *PQconnectdb(const char *conninfo);
int PQstatus(const PGconn *conn);
char *PQerrorMessage(const PGconn *conn);
void PQfinish(PGconn *conn);
PGresult *PQexec(PGconn *conn, const char *query);
int PQresultStatus(const PGresult *res);
int PQntuples(const PGresult *res);
int PQnfields(const PGresult *res);
char *PQfname(const PGresult *res, int field_num);
char *PQgetvalue(const PGresult *res, int tup_num, int field_num);
int PQgetisnull(const PGresult *res, int tup_num, int field_num);
void PQclear(PGresult *res);
size_t PQresultMemorySize(const PGresult *res);
int PQputCopyData(PGconn *conn, const char *buffer, int nbytes);
int PQputCopyEnd(PGconn *conn, const char *errormsg);
int PQsocket(const PGconn *conn);
char *PQescapeIdentifier(PGconn *conn, const char *str, size_t length);
char *PQescapeLiteral(PGconn *conn, const char *str, size_t length);
void PQfreemem(void *ptr);
CDEF;

        foreach (['libpq.so.5', 'libpq.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

/**
 * libsqlite3 FFI bridge (php-src ext/sqlite3/sqlite3.c; issue #3434).
 */
final class VmSqlite3Native
{
    private const SQLITE_OK = 0;

    private const SQLITE_ROW = 100;

    private const SQLITE_INTEGER = 1;

    private const SQLITE_FLOAT = 2;

    private const SQLITE_TEXT = 3;

    private const SQLITE_NULL = 5;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return \FFI\CData sqlite3*
     */
    public static function open(string $filename, int $flags): \FFI\CData
    {
        $ffi = self::requireFfi();
        $dbPtr = $ffi->new('sqlite3*');
        $rc = (int) $ffi->sqlite3_open_v2($filename, \FFI::addr($dbPtr), $flags, null);
        if (self::SQLITE_OK !== $rc) {
            $message = self::errmsg($dbPtr);
            if ('' === $message) {
                $message = 'Unable to open database';
            }
            if (null !== $dbPtr) {
                $ffi->sqlite3_close($dbPtr);
            }
            throw new \SQLite3Exception($message);
        }

        return $dbPtr;
    }

    public static function close(\FFI\CData $db): bool
    {
        $ffi = self::requireFfi();
        $rc = (int) $ffi->sqlite3_close($db);

        return self::SQLITE_OK === $rc;
    }

    public static function exec(\FFI\CData $db, string $sql): bool
    {
        $ffi = self::requireFfi();
        $errMsgPtr = $ffi->new('char*');
        $rc = (int) $ffi->sqlite3_exec($db, $sql, null, null, \FFI::addr($errMsgPtr));
        if (self::SQLITE_OK !== $rc) {
            $message = self::ffiString($errMsgPtr);
            if ('' === $message) {
                $message = self::errmsg($db);
            }
            if ('' !== self::ffiString($errMsgPtr)) {
                $ffi->sqlite3_free($errMsgPtr);
            }
            throw new \SQLite3Exception($message);
        }

        return true;
    }

    /**
     * @return array<int, string|int|float|null>|string|int|float|null|false
     */
    public static function querySingle(\FFI\CData $db, string $sql, bool $entireRow = false): array|string|int|float|null|false
    {
        $ffi = self::requireFfi();
        $stmtPtr = $ffi->new('sqlite3_stmt*');
        $rc = (int) $ffi->sqlite3_prepare_v2($db, $sql, -1, \FFI::addr($stmtPtr), null);
        if (self::SQLITE_OK !== $rc) {
            throw new \SQLite3Exception(self::errmsg($db));
        }

        try {
            $step = (int) $ffi->sqlite3_step($stmtPtr);
            if (self::SQLITE_ROW !== $step) {
                return false;
            }

            $columnCount = (int) $ffi->sqlite3_column_count($stmtPtr);
            if ($columnCount <= 0) {
                return false;
            }

            if ($entireRow) {
                $row = [];
                for ($i = 0; $i < $columnCount; ++$i) {
                    $row[$i] = self::columnValue($ffi, $stmtPtr, $i);
                }

                return $row;
            }

            return self::columnValue($ffi, $stmtPtr, 0);
        } finally {
            $ffi->sqlite3_finalize($stmtPtr);
        }
    }

    public static function errmsg(\FFI\CData $db): string
    {
        $ffi = self::requireFfi();

        return self::ffiString($ffi->sqlite3_errmsg($db));
    }

    /** @return \FFI\CData|string|int|float|null */
    private static function columnValue(\FFI $ffi, \FFI\CData $stmt, int $index): string|int|float|null
    {
        $type = (int) $ffi->sqlite3_column_type($stmt, $index);
        switch ($type) {
            case self::SQLITE_INTEGER:
                return (int) $ffi->sqlite3_column_int64($stmt, $index);
            case self::SQLITE_FLOAT:
                return (float) $ffi->sqlite3_column_double($stmt, $index);
            case self::SQLITE_NULL:
                return null;
            default:
                $text = $ffi->sqlite3_column_text($stmt, $index);
                if (null === $text) {
                    return '';
                }

                return self::ffiString(\FFI::cast('char*', $text));
        }
    }

    /** @param \FFI\CData|string|null $ptr */
    private static function ffiString($ptr): string
    {
        if (null === $ptr) {
            return '';
        }
        if (\is_string($ptr)) {
            return $ptr;
        }

        return \FFI::string($ptr);
    }

    private static function requireFfi(): \FFI
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \LogicException('libsqlite3 FFI unavailable in this compiler build');
        }

        return $ffi;
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
typedef long long sqlite3_int64;
typedef struct sqlite3 sqlite3;
typedef struct sqlite3_stmt sqlite3_stmt;

int sqlite3_open_v2(const char *filename, sqlite3 **ppDb, int flags, const char *zVfs);
int sqlite3_close(sqlite3 *db);
int sqlite3_exec(sqlite3 *db, const char *sql, int (*callback)(void*,int,char**,char**), void *arg, char **errmsg);
void sqlite3_free(void *p);
const char *sqlite3_errmsg(sqlite3 *db);
int sqlite3_prepare_v2(sqlite3 *db, const char *zSql, int nByte, sqlite3_stmt **ppStmt, const char **pzTail);
int sqlite3_step(sqlite3_stmt *pStmt);
int sqlite3_column_count(sqlite3_stmt *pStmt);
int sqlite3_column_type(sqlite3_stmt *pStmt, int iCol);
const unsigned char *sqlite3_column_text(sqlite3_stmt *pStmt, int iCol);
sqlite3_int64 sqlite3_column_int64(sqlite3_stmt *pStmt, int iCol);
double sqlite3_column_double(sqlite3_stmt *pStmt, int iCol);
int sqlite3_finalize(sqlite3_stmt *pStmt);
CDEF;

        foreach (['libsqlite3.so.0', 'libsqlite3.so'] as $lib) {
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

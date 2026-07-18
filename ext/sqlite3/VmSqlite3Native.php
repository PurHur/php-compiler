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

    /**
     * Prepare a statement (caller must finalize). Used by ext/pdo (#3367).
     *
     * @return \FFI\CData sqlite3_stmt*
     */
    public static function prepare(\FFI\CData $db, string $sql): \FFI\CData
    {
        $ffi = self::requireFfi();
        $stmtPtr = $ffi->new('sqlite3_stmt*');
        $rc = (int) $ffi->sqlite3_prepare_v2($db, $sql, -1, \FFI::addr($stmtPtr), null);
        if (self::SQLITE_OK !== $rc) {
            throw new \SQLite3Exception(self::errmsg($db));
        }

        return $stmtPtr;
    }

    public static function finalize(\FFI\CData $stmt): void
    {
        self::requireFfi()->sqlite3_finalize($stmt);
    }

    public static function reset(\FFI\CData $stmt): void
    {
        self::requireFfi()->sqlite3_reset($stmt);
    }

    public static function clearBindings(\FFI\CData $stmt): void
    {
        self::requireFfi()->sqlite3_clear_bindings($stmt);
    }

    public static function bindValue(\FFI\CData $stmt, int $index, mixed $value): void
    {
        $ffi = self::requireFfi();
        if (null === $value) {
            $rc = (int) $ffi->sqlite3_bind_null($stmt, $index);
        } elseif (\is_bool($value)) {
            $rc = (int) $ffi->sqlite3_bind_int($stmt, $index, $value ? 1 : 0);
        } elseif (\is_int($value)) {
            $rc = (int) $ffi->sqlite3_bind_int64($stmt, $index, $value);
        } elseif (\is_float($value)) {
            $rc = (int) $ffi->sqlite3_bind_double($stmt, $index, $value);
        } else {
            $text = (string) $value;
            // SQLITE_TRANSIENT (-1): copy binding so PHP string lifetime is safe.
            $rc = (int) $ffi->sqlite3_bind_text(
                $stmt,
                $index,
                $text,
                \strlen($text),
                \FFI::cast('void*', -1)
            );
        }
        if (self::SQLITE_OK !== $rc) {
            throw new \SQLite3Exception('Failed to bind parameter '.$index);
        }
    }

    /** @return int SQLITE_ROW (100), SQLITE_DONE (101), or error */
    public static function step(\FFI\CData $stmt): int
    {
        return (int) self::requireFfi()->sqlite3_step($stmt);
    }

    public static function columnCount(\FFI\CData $stmt): int
    {
        return (int) self::requireFfi()->sqlite3_column_count($stmt);
    }

    public static function columnName(\FFI\CData $stmt, int $index): string
    {
        $ffi = self::requireFfi();
        $name = $ffi->sqlite3_column_name($stmt, $index);

        return null === $name ? '' : self::ffiString($name);
    }

    /** @return string|int|float|null */
    public static function columnValueAt(\FFI\CData $stmt, int $index): string|int|float|null
    {
        return self::columnValue(self::requireFfi(), $stmt, $index);
    }

    public static function changes(\FFI\CData $db): int
    {
        return (int) self::requireFfi()->sqlite3_changes($db);
    }

    public static function lastInsertRowId(\FFI\CData $db): int
    {
        return (int) self::requireFfi()->sqlite3_last_insert_rowid($db);
    }

    public static function errcode(\FFI\CData $db): int
    {
        return (int) self::requireFfi()->sqlite3_errcode($db);
    }

    /** @return array{versionString: string, versionNumber: int} */
    public static function version(): array
    {
        $ffi = self::requireFfi();

        return [
            'versionString' => self::ffiString($ffi->sqlite3_libversion()),
            'versionNumber' => (int) $ffi->sqlite3_libversion_number(),
        ];
    }

    /**
     * SQLite3::backup — copy source DB into destination (php-src; #20565).
     *
     * @param \FFI\CData $sourceDb sqlite3*
     * @param \FFI\CData $destDb   sqlite3*
     */
    public static function backup(
        \FFI\CData $sourceDb,
        string $sourceName,
        \FFI\CData $destDb,
        string $destName
    ): bool {
        $ffi = self::requireFfi();
        $backup = $ffi->sqlite3_backup_init($destDb, $destName, $sourceDb, $sourceName);
        if (null === $backup) {
            $rc = (int) $ffi->sqlite3_errcode($sourceDb);
        } else {
            do {
                $rc = (int) $ffi->sqlite3_backup_step($backup, -1);
            } while (self::SQLITE_OK === $rc);
            $rc = (int) $ffi->sqlite3_backup_finish($backup);
        }
        if (self::SQLITE_OK !== $rc) {
            if (5 === $rc) { // SQLITE_BUSY
                throw new \SQLite3Exception('Backup failed: source database is busy');
            }
            if (6 === $rc) { // SQLITE_LOCKED
                throw new \SQLite3Exception('Backup failed: source database is locked');
            }
            $message = self::errmsg($sourceDb);
            throw new \SQLite3Exception('Backup failed: '.('' !== $message ? $message : 'unknown error'));
        }

        return true;
    }

    /** php-src SQLite3::escapeString — sqlite3_mprintf("%q", …). */
    public static function escapeString(string $value): string
    {
        if ('' === $value) {
            return '';
        }
        $ffi = self::requireFfi();
        $ret = $ffi->sqlite3_mprintf('%q', $value);
        if (null === $ret) {
            return '';
        }
        $out = self::ffiString($ret);
        $ffi->sqlite3_free($ret);

        return $out;
    }

    /**
     * PDO sqlite quoter — sqlite3_mprintf("%Q", …) (escaped + surrounding quotes).
     * php-src: ext/pdo_sqlite/sqlite_driver.c sqlite_handle_quoter.
     */
    public static function quoteSqlLiteral(string $value): string
    {
        $ffi = self::requireFfi();
        $ret = $ffi->sqlite3_mprintf('%Q', $value);
        if (null === $ret) {
            return "''";
        }
        $out = self::ffiString($ret);
        $ffi->sqlite3_free($ret);

        return $out;
    }

    public static function columnTypeAt(\FFI\CData $stmt, int $index): int
    {
        return (int) self::requireFfi()->sqlite3_column_type($stmt, $index);
    }

    public static function bindParameterCount(\FFI\CData $stmt): int
    {
        return (int) self::requireFfi()->sqlite3_bind_parameter_count($stmt);
    }

    /** 1-based index, or 0 when the name is unknown (sqlite3_bind_parameter_index). */
    public static function bindParameterIndex(\FFI\CData $stmt, string $name): int
    {
        return (int) self::requireFfi()->sqlite3_bind_parameter_index($stmt, $name);
    }

    public static function sql(\FFI\CData $stmt): string
    {
        $sql = self::requireFfi()->sqlite3_sql($stmt);

        return null === $sql ? '' : self::ffiString($sql);
    }

    /** Requires SQLite ≥ 3.14; caller must free via sqlite3_free. */
    public static function expandedSql(\FFI\CData $stmt): ?string
    {
        $ffi = self::requireFfi();
        $sql = $ffi->sqlite3_expanded_sql($stmt);
        if (null === $sql) {
            return null;
        }
        $out = self::ffiString($sql);
        $ffi->sqlite3_free($sql);

        return $out;
    }

    public static function stmtReadonly(\FFI\CData $stmt): bool
    {
        return 0 !== (int) self::requireFfi()->sqlite3_stmt_readonly($stmt);
    }

    /** Columns in the current row; 0 before first step / after reset (php-src columnType). */
    public static function dataCount(\FFI\CData $stmt): int
    {
        return (int) self::requireFfi()->sqlite3_data_count($stmt);
    }

    /** Declared column type from CREATE TABLE, or '' when unknown (sqlite3_column_decltype). */
    public static function columnDecltype(\FFI\CData $stmt, int $index): string
    {
        $name = self::requireFfi()->sqlite3_column_decltype($stmt, $index);

        return null === $name ? '' : self::ffiString($name);
    }

    /** php-src SQLite3::busyTimeout — sqlite3_busy_timeout (#19862). */
    public static function busyTimeout(\FFI\CData $db, int $milliseconds): bool
    {
        $rc = (int) self::requireFfi()->sqlite3_busy_timeout($db, $milliseconds);

        return self::SQLITE_OK === $rc;
    }

    public const STEP_ROW = 100;

    public const STEP_DONE = 101;

    public const TYPE_INTEGER = 1;

    public const TYPE_FLOAT = 2;

    public const TYPE_TEXT = 3;

    public const TYPE_BLOB = 4;

    public const TYPE_NULL = 5;

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
int sqlite3_reset(sqlite3_stmt *pStmt);
int sqlite3_clear_bindings(sqlite3_stmt *pStmt);
int sqlite3_bind_null(sqlite3_stmt *pStmt, int i);
int sqlite3_bind_int(sqlite3_stmt *pStmt, int i, int iValue);
int sqlite3_bind_int64(sqlite3_stmt *pStmt, int i, sqlite3_int64 iValue);
int sqlite3_bind_double(sqlite3_stmt *pStmt, int i, double dValue);
int sqlite3_bind_text(sqlite3_stmt *pStmt, int i, const char *zData, int nData, void *xDel);
int sqlite3_column_count(sqlite3_stmt *pStmt);
const char *sqlite3_column_name(sqlite3_stmt *pStmt, int N);
int sqlite3_column_type(sqlite3_stmt *pStmt, int iCol);
const unsigned char *sqlite3_column_text(sqlite3_stmt *pStmt, int iCol);
sqlite3_int64 sqlite3_column_int64(sqlite3_stmt *pStmt, int iCol);
double sqlite3_column_double(sqlite3_stmt *pStmt, int iCol);
int sqlite3_finalize(sqlite3_stmt *pStmt);
int sqlite3_changes(sqlite3 *db);
sqlite3_int64 sqlite3_last_insert_rowid(sqlite3 *db);
int sqlite3_errcode(sqlite3 *db);
const char *sqlite3_libversion(void);
int sqlite3_libversion_number(void);
typedef struct sqlite3_backup sqlite3_backup;
sqlite3_backup *sqlite3_backup_init(sqlite3 *pDest, const char *zDestName, sqlite3 *pSource, const char *zSourceName);
int sqlite3_backup_step(sqlite3_backup *p, int nPage);
int sqlite3_backup_finish(sqlite3_backup *p);
char *sqlite3_mprintf(const char *zFormat, ...);
int sqlite3_bind_parameter_count(sqlite3_stmt *pStmt);
int sqlite3_bind_parameter_index(sqlite3_stmt *pStmt, const char *zName);
const char *sqlite3_sql(sqlite3_stmt *pStmt);
char *sqlite3_expanded_sql(sqlite3_stmt *pStmt);
int sqlite3_stmt_readonly(sqlite3_stmt *pStmt);
int sqlite3_data_count(sqlite3_stmt *pStmt);
const char *sqlite3_column_decltype(sqlite3_stmt *pStmt, int N);
int sqlite3_busy_timeout(sqlite3 *db, int ms);
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

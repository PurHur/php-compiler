<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

/**
 * Thin unixODBC FFI bridge (php-src ext/odbc/php_odbc.c; #6293 / #21258).
 *
 * Best-effort — any FFI / driver failure returns null/false so callers emit
 * Zend-shaped warnings.
 */
final class VmOdbcNative
{
    public const SQL_SUCCESS = 0;

    public const SQL_SUCCESS_WITH_INFO = 1;

    public const SQL_NO_DATA = 100;

    public const SQL_NTS = -3;

    public const SQL_NULL_DATA = -1;

    public const SQL_DRIVER_NOPROMPT = 0;

    public const SQL_DROP = 1;

    public const SQL_UNBIND = 2;

    public const SQL_RESET_PARAMS = 3;

    public const SQL_PARAM_INPUT = 1;

    public const SQL_C_CHAR = 1;

    public const SQL_VARCHAR = 12;

    public const SQL_HANDLE_STMT = 3;

    /** ODBC 2 SQL_AUTOCOMMIT / SQL_ATTR_AUTOCOMMIT (sql.h). */
    public const SQL_AUTOCOMMIT = 102;

    public const SQL_AUTOCOMMIT_OFF = 0;

    public const SQL_AUTOCOMMIT_ON = 1;

    public const SQL_COMMIT = 0;

    public const SQL_ROLLBACK = 1;

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
                $dsnBuf = self::cString($ffi, $dsn);
                $out = $ffi->new('SQLCHAR[1024]');
                $outLen = $ffi->new('SQLSMALLINT');
                $rc = (int) $ffi->SQLDriverConnect(
                    $hdbc,
                    null,
                    $dsnBuf,
                    \strlen($dsn),
                    $out,
                    1023,
                    \FFI::addr($outLen),
                    self::SQL_DRIVER_NOPROMPT
                );
            } else {
                $dsnBuf = self::cString($ffi, $dsn);
                $userBuf = self::cString($ffi, $user);
                $passBuf = self::cString($ffi, $password);
                $rc = (int) $ffi->SQLConnect(
                    $hdbc,
                    $dsnBuf,
                    self::SQL_NTS,
                    $userBuf,
                    self::SQL_NTS,
                    $passBuf,
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
     * @return \FFI\CData|null SQLHSTMT
     */
    public static function allocStmt(\FFI\CData $hdbc): ?\FFI\CData
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return null;
            }
            $hstmt = $ffi->new('SQLHSTMT');
            $rc = (int) $ffi->SQLAllocStmt($hdbc, \FFI::addr($hstmt));
            if (!self::ok($rc)) {
                return null;
            }

            return $hstmt;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function freeStmt(?\FFI\CData $hstmt): void
    {
        if (null === $hstmt) {
            return;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        try {
            @$ffi->SQLFreeStmt($hstmt, self::SQL_DROP);
        } catch (\Throwable $e) {
        }
    }

    public static function unbindStmt(?\FFI\CData $hstmt): void
    {
        if (null === $hstmt) {
            return;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        try {
            @$ffi->SQLFreeStmt($hstmt, self::SQL_UNBIND);
        } catch (\Throwable $e) {
        }
    }

    /**
     * SQLMoreResults — true on success, false on SQL_NO_DATA, null on error.
     */
    public static function moreResults(\FFI\CData $hstmt): ?bool
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return null;
            }
            $rc = (int) $ffi->SQLMoreResults($hstmt);
            if (self::ok($rc)) {
                return true;
            }
            if (self::SQL_NO_DATA === $rc) {
                return false;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * SQLDataSources — array{server, description}|null when no data|false on error.
     *
     * @return array{server: string, description: string}|null|false
     */
    public static function dataSources(\FFI\CData $henv, int $fetchType): array|null|false
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $server = $ffi->new('SQLCHAR[100]');
            $desc = $ffi->new('SQLCHAR[200]');
            $len1 = $ffi->new('SQLSMALLINT');
            $len2 = $ffi->new('SQLSMALLINT');
            $rc = (int) $ffi->SQLDataSources(
                $henv,
                $fetchType,
                $server,
                100,
                \FFI::addr($len1),
                $desc,
                200,
                \FFI::addr($len2)
            );
            if (self::SQL_NO_DATA === $rc) {
                return null;
            }
            if (!self::ok($rc)) {
                return false;
            }
            $n1 = (int) $len1->cdata;
            $n2 = (int) $len2->cdata;
            if (0 === $n1 || 0 === $n2) {
                return false;
            }

            return [
                'server' => self::ffiString($server, $n1),
                'description' => self::ffiString($desc, $n2),
            ];
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function prepare(\FFI\CData $hstmt, string $query): bool
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $q = self::cString($ffi, $query);
            $rc = (int) $ffi->SQLPrepare($hstmt, $q, self::SQL_NTS);

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function execute(\FFI\CData $hstmt): bool
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $rc = (int) $ffi->SQLExecute($hstmt);

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function execDirect(\FFI\CData $hstmt, string $query): bool
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $q = self::cString($ffi, $query);
            $rc = (int) $ffi->SQLExecDirect($hstmt, $q, self::SQL_NTS);

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function numResultCols(\FFI\CData $hstmt): int
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return 0;
            }
            $ncols = $ffi->new('SQLSMALLINT');
            $rc = (int) $ffi->SQLNumResultCols($hstmt, \FFI::addr($ncols));
            if (!self::ok($rc)) {
                return 0;
            }

            return (int) $ncols->cdata;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function numParams(\FFI\CData $hstmt): int
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return 0;
            }
            $n = $ffi->new('SQLSMALLINT');
            $rc = (int) $ffi->SQLNumParams($hstmt, \FFI::addr($n));
            if (!self::ok($rc)) {
                return 0;
            }

            return (int) $n->cdata;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array{name: string, type: string, len: int}|null
     */
    public static function describeCol(\FFI\CData $hstmt, int $col1Based): ?array
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return null;
            }
            $name = $ffi->new('SQLCHAR[256]');
            $nameLen = $ffi->new('SQLSMALLINT');
            $dataType = $ffi->new('SQLSMALLINT');
            $colSize = $ffi->new('SQLULEN');
            $decimal = $ffi->new('SQLSMALLINT');
            $nullable = $ffi->new('SQLSMALLINT');
            $rc = (int) $ffi->SQLDescribeCol(
                $hstmt,
                $col1Based,
                $name,
                255,
                \FFI::addr($nameLen),
                \FFI::addr($dataType),
                \FFI::addr($colSize),
                \FFI::addr($decimal),
                \FFI::addr($nullable)
            );
            if (!self::ok($rc)) {
                return null;
            }

            return [
                'name' => self::ffiString($name, (int) $nameLen->cdata),
                'type' => (string) (int) $dataType->cdata,
                'len' => (int) $colSize->cdata,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function fetch(\FFI\CData $hstmt): bool
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $rc = (int) $ffi->SQLFetch($hstmt);
            if (self::SQL_NO_DATA === $rc) {
                return false;
            }

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return string|null|false false on error, null for SQL NULL
     */
    public static function getData(\FFI\CData $hstmt, int $col1Based): string|null|false
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $buf = $ffi->new('SQLCHAR[4096]');
            $ind = $ffi->new('SQLLEN');
            $rc = (int) $ffi->SQLGetData(
                $hstmt,
                $col1Based,
                self::SQL_C_CHAR,
                $buf,
                4095,
                \FFI::addr($ind)
            );
            if (!self::ok($rc) && self::SQL_NO_DATA !== $rc) {
                return false;
            }
            if (self::SQL_NULL_DATA === (int) $ind->cdata) {
                return null;
            }

            return self::ffiString($buf, (int) $ind->cdata > 0 ? (int) $ind->cdata : null);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Bind a string/null parameter (1-based index). Keeps CData alive via returned bag.
     *
     * @return array{buf: \FFI\CData, ind: \FFI\CData}|null
     */
    public static function bindStringParam(\FFI\CData $hstmt, int $idx1Based, ?string $value): ?array
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return null;
            }
            $ind = $ffi->new('SQLLEN');
            if (null === $value) {
                $ind->cdata = self::SQL_NULL_DATA;
                $buf = $ffi->new('SQLCHAR[1]');
                $buf[0] = 0;
                $rc = (int) $ffi->SQLBindParameter(
                    $hstmt,
                    $idx1Based,
                    self::SQL_PARAM_INPUT,
                    self::SQL_C_CHAR,
                    self::SQL_VARCHAR,
                    0,
                    0,
                    null,
                    0,
                    \FFI::addr($ind)
                );
            } else {
                $len = \strlen($value);
                $buf = $ffi->new('SQLCHAR['.($len + 1).']');
                \FFI::memcpy($buf, $value."\0", $len + 1);
                $ind->cdata = $len;
                $rc = (int) $ffi->SQLBindParameter(
                    $hstmt,
                    $idx1Based,
                    self::SQL_PARAM_INPUT,
                    self::SQL_C_CHAR,
                    self::SQL_VARCHAR,
                    $len,
                    0,
                    $buf,
                    $len + 1,
                    \FFI::addr($ind)
                );
            }
            if (!self::ok($rc)) {
                return null;
            }

            return ['buf' => $buf, 'ind' => $ind];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function tables(
        \FFI\CData $hstmt,
        ?string $catalog,
        ?string $schema,
        ?string $table,
        ?string $types
    ): bool {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $catBuf = null === $catalog ? null : self::cString($ffi, $catalog);
            $schBuf = null === $schema ? null : self::cString($ffi, $schema);
            $tabBuf = null === $table ? null : self::cString($ffi, $table);
            $typBuf = null === $types ? null : self::cString($ffi, $types);
            $rc = (int) $ffi->SQLTables(
                $hstmt,
                $catBuf,
                null === $catalog ? 0 : self::SQL_NTS,
                $schBuf,
                null === $schema ? 0 : self::SQL_NTS,
                $tabBuf,
                null === $table ? 0 : self::SQL_NTS,
                $typBuf,
                null === $types ? 0 : self::SQL_NTS
            );

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function columns(
        \FFI\CData $hstmt,
        ?string $catalog,
        ?string $schema,
        ?string $table,
        ?string $column
    ): bool {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $catBuf = null === $catalog ? null : self::cString($ffi, $catalog);
            $schBuf = null === $schema ? null : self::cString($ffi, $schema);
            $tabBuf = null === $table ? null : self::cString($ffi, $table);
            $colBuf = null === $column ? null : self::cString($ffi, $column);
            $rc = (int) $ffi->SQLColumns(
                $hstmt,
                $catBuf,
                null === $catalog ? 0 : self::SQL_NTS,
                $schBuf,
                null === $schema ? 0 : self::SQL_NTS,
                $tabBuf,
                null === $table ? 0 : self::SQL_NTS,
                $colBuf,
                null === $column ? 0 : self::SQL_NTS
            );

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * SQLPrimaryKeys (php-src odbc_primarykeys; #21279).
     */
    public static function primaryKeys(
        \FFI\CData $hstmt,
        ?string $catalog,
        string $schema,
        string $table
    ): bool {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $catBuf = null === $catalog ? null : self::cString($ffi, $catalog);
            $schBuf = self::cString($ffi, $schema);
            $tabBuf = self::cString($ffi, $table);
            $rc = (int) $ffi->SQLPrimaryKeys(
                $hstmt,
                $catBuf,
                null === $catalog ? 0 : self::SQL_NTS,
                $schBuf,
                self::SQL_NTS,
                $tabBuf,
                self::SQL_NTS
            );

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * SQLForeignKeys (php-src odbc_foreignkeys; #21279).
     */
    public static function foreignKeys(
        \FFI\CData $hstmt,
        ?string $pkCatalog,
        string $pkSchema,
        string $pkTable,
        string $fkCatalog,
        string $fkSchema,
        string $fkTable
    ): bool {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $pcat = null === $pkCatalog ? null : self::cString($ffi, $pkCatalog);
            $rc = (int) $ffi->SQLForeignKeys(
                $hstmt,
                $pcat,
                null === $pkCatalog ? 0 : self::SQL_NTS,
                self::cString($ffi, $pkSchema),
                self::SQL_NTS,
                self::cString($ffi, $pkTable),
                self::SQL_NTS,
                self::cString($ffi, $fkCatalog),
                self::SQL_NTS,
                self::cString($ffi, $fkSchema),
                self::SQL_NTS,
                self::cString($ffi, $fkTable),
                self::SQL_NTS
            );

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * SQLStatistics (php-src odbc_statistics; #21279).
     */
    public static function statistics(
        \FFI\CData $hstmt,
        ?string $catalog,
        string $schema,
        string $table,
        int $unique,
        int $accuracy
    ): bool {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $catBuf = null === $catalog ? null : self::cString($ffi, $catalog);
            $rc = (int) $ffi->SQLStatistics(
                $hstmt,
                $catBuf,
                null === $catalog ? 0 : self::SQL_NTS,
                self::cString($ffi, $schema),
                self::SQL_NTS,
                self::cString($ffi, $table),
                self::SQL_NTS,
                $unique,
                $accuracy
            );

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * SQLGetTypeInfo (php-src odbc_gettypeinfo; #21279).
     */
    public static function getTypeInfo(\FFI\CData $hstmt, int $dataType): bool
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $rc = (int) $ffi->SQLGetTypeInfo($hstmt, $dataType);

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Set SQL_AUTOCOMMIT (php-src SQLSetConnectOption; #21277).
     */
    public static function setAutocommit(\FFI\CData $hdbc, bool $enable): bool
    {
        $value = $enable ? self::SQL_AUTOCOMMIT_ON : self::SQL_AUTOCOMMIT_OFF;

        return self::setConnectOption($hdbc, self::SQL_AUTOCOMMIT, $value);
    }

    /**
     * SQLSetConnectOption (php-src odbc_setoption which=1; #21267).
     */
    public static function setConnectOption(\FFI\CData $hdbc, int $option, int $value): bool
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $rc = (int) $ffi->SQLSetConnectOption($hdbc, $option, $value);

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * SQLSetStmtOption (php-src odbc_setoption which=2; #21267).
     */
    public static function setStmtOption(\FFI\CData $hstmt, int $option, int $value): bool
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $rc = (int) $ffi->SQLSetStmtOption($hstmt, $option, $value);

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get SQL_AUTOCOMMIT status (0/1). null on error.
     */
    public static function getAutocommit(\FFI\CData $hdbc): ?int
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return null;
            }
            $status = $ffi->new('SQLULEN');
            $rc = (int) $ffi->SQLGetConnectOption($hdbc, self::SQL_AUTOCOMMIT, \FFI::addr($status));
            if (!self::ok($rc)) {
                return null;
            }

            return (int) $status->cdata;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * SQLTransact commit/rollback (php-src odbc_transact; #21277).
     */
    public static function transact(\FFI\CData $henv, \FFI\CData $hdbc, bool $commit): bool
    {
        try {
            $ffi = self::ffi();
            if (null === $ffi) {
                return false;
            }
            $type = $commit ? self::SQL_COMMIT : self::SQL_ROLLBACK;
            $rc = (int) $ffi->SQLTransact($henv, $hdbc, $type);

            return self::ok($rc);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Buffer all current result rows + column metadata.
     *
     * @return array{rows: list<list<mixed>>, colnames: list<string>, coltypes: list<string>, collens: list<int>}|null
     */
    public static function bufferResult(\FFI\CData $hstmt): ?array
    {
        $ncols = self::numResultCols($hstmt);
        $colnames = [];
        $coltypes = [];
        $collens = [];
        for ($i = 1; $i <= $ncols; ++$i) {
            $meta = self::describeCol($hstmt, $i);
            if (null === $meta) {
                $colnames[] = 'col'.$i;
                $coltypes[] = '';
                $collens[] = 0;
            } else {
                $colnames[] = $meta['name'];
                $coltypes[] = $meta['type'];
                $collens[] = $meta['len'];
            }
        }
        $rows = [];
        if ($ncols > 0) {
            while (self::fetch($hstmt)) {
                $row = [];
                for ($i = 1; $i <= $ncols; ++$i) {
                    $val = self::getData($hstmt, $i);
                    if (false === $val) {
                        $row[] = null;
                    } else {
                        $row[] = $val;
                    }
                }
                $rows[] = $row;
            }
        }

        return [
            'rows' => $rows,
            'colnames' => $colnames,
            'coltypes' => $coltypes,
            'collens' => $collens,
        ];
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
typedef unsigned short SQLUSMALLINT;
typedef int SQLINTEGER;
typedef long SQLLEN;
typedef unsigned long SQLULEN;
typedef unsigned char SQLCHAR;
typedef void *SQLPOINTER;

SQLRETURN SQLAllocEnv(SQLHENV *phenv);
SQLRETURN SQLAllocConnect(SQLHENV henv, SQLHDBC *phdbc);
SQLRETURN SQLAllocStmt(SQLHDBC hdbc, SQLHSTMT *phstmt);
SQLRETURN SQLConnect(SQLHDBC hdbc, SQLCHAR *szDSN, SQLSMALLINT cbDSN, SQLCHAR *szUID, SQLSMALLINT cbUID, SQLCHAR *szAuthStr, SQLSMALLINT cbAuthStr);
SQLRETURN SQLDriverConnect(SQLHDBC hdbc, void *hwnd, SQLCHAR *szConnStrIn, SQLSMALLINT cbConnStrIn, SQLCHAR *szConnStrOut, SQLSMALLINT cbConnStrOutMax, SQLSMALLINT *pcbConnStrOut, SQLSMALLINT fDriverCompletion);
SQLRETURN SQLDisconnect(SQLHDBC hdbc);
SQLRETURN SQLFreeConnect(SQLHDBC hdbc);
SQLRETURN SQLFreeEnv(SQLHENV henv);
SQLRETURN SQLFreeStmt(SQLHSTMT hstmt, SQLSMALLINT Option);
SQLRETURN SQLPrepare(SQLHSTMT hstmt, SQLCHAR *szSqlStr, SQLINTEGER cbSqlStr);
SQLRETURN SQLExecute(SQLHSTMT hstmt);
SQLRETURN SQLExecDirect(SQLHSTMT hstmt, SQLCHAR *szSqlStr, SQLINTEGER cbSqlStr);
SQLRETURN SQLNumResultCols(SQLHSTMT hstmt, SQLSMALLINT *pccol);
SQLRETURN SQLNumParams(SQLHSTMT hstmt, SQLSMALLINT *pcpar);
SQLRETURN SQLDescribeCol(SQLHSTMT hstmt, SQLUSMALLINT icol, SQLCHAR *szColName, SQLSMALLINT cbColNameMax, SQLSMALLINT *pcbColName, SQLSMALLINT *pfSqlType, SQLULEN *pcbColDef, SQLSMALLINT *pibScale, SQLSMALLINT *pfNullable);
SQLRETURN SQLColAttribute(SQLHSTMT hstmt, SQLUSMALLINT iCol, SQLUSMALLINT iField, SQLPOINTER pCharAttr, SQLSMALLINT cbCharAttrMax, SQLSMALLINT *pcbCharAttr, SQLLEN *pNumAttr);
SQLRETURN SQLFetch(SQLHSTMT hstmt);
SQLRETURN SQLGetData(SQLHSTMT hstmt, SQLUSMALLINT icol, SQLSMALLINT fCType, SQLPOINTER rgbValue, SQLLEN cbValueMax, SQLLEN *pcbValue);
SQLRETURN SQLBindParameter(SQLHSTMT hstmt, SQLUSMALLINT ipar, SQLSMALLINT fParamType, SQLSMALLINT fCType, SQLSMALLINT fSqlType, SQLULEN cbColDef, SQLSMALLINT ibScale, SQLPOINTER rgbValue, SQLLEN cbValueMax, SQLLEN *pcbValue);
SQLRETURN SQLTables(SQLHSTMT hstmt, SQLCHAR *szTableQualifier, SQLSMALLINT cbTableQualifier, SQLCHAR *szTableOwner, SQLSMALLINT cbTableOwner, SQLCHAR *szTableName, SQLSMALLINT cbTableName, SQLCHAR *szTableType, SQLSMALLINT cbTableType);
SQLRETURN SQLColumns(SQLHSTMT hstmt, SQLCHAR *szTableQualifier, SQLSMALLINT cbTableQualifier, SQLCHAR *szTableOwner, SQLSMALLINT cbTableOwner, SQLCHAR *szTableName, SQLSMALLINT cbTableName, SQLCHAR *szColumnName, SQLSMALLINT cbColumnName);
SQLRETURN SQLPrimaryKeys(SQLHSTMT hstmt, SQLCHAR *szCatalogName, SQLSMALLINT cbCatalogName, SQLCHAR *szSchemaName, SQLSMALLINT cbSchemaName, SQLCHAR *szTableName, SQLSMALLINT cbTableName);
SQLRETURN SQLForeignKeys(SQLHSTMT hstmt, SQLCHAR *szPkCatalogName, SQLSMALLINT cbPkCatalogName, SQLCHAR *szPkSchemaName, SQLSMALLINT cbPkSchemaName, SQLCHAR *szPkTableName, SQLSMALLINT cbPkTableName, SQLCHAR *szFkCatalogName, SQLSMALLINT cbFkCatalogName, SQLCHAR *szFkSchemaName, SQLSMALLINT cbFkSchemaName, SQLCHAR *szFkTableName, SQLSMALLINT cbFkTableName);
SQLRETURN SQLStatistics(SQLHSTMT hstmt, SQLCHAR *szCatalogName, SQLSMALLINT cbCatalogName, SQLCHAR *szSchemaName, SQLSMALLINT cbSchemaName, SQLCHAR *szTableName, SQLSMALLINT cbTableName, SQLUSMALLINT fUnique, SQLUSMALLINT fAccuracy);
SQLRETURN SQLGetTypeInfo(SQLHSTMT hstmt, SQLSMALLINT fSqlType);
SQLRETURN SQLSetConnectOption(SQLHDBC hdbc, SQLUSMALLINT fOption, SQLULEN vParam);
SQLRETURN SQLSetStmtOption(SQLHSTMT hstmt, SQLUSMALLINT fOption, SQLULEN vParam);
SQLRETURN SQLGetConnectOption(SQLHDBC hdbc, SQLUSMALLINT fOption, SQLPOINTER pvParam);
SQLRETURN SQLTransact(SQLHENV henv, SQLHDBC hdbc, SQLUSMALLINT fType);
SQLRETURN SQLMoreResults(SQLHSTMT hstmt);
SQLRETURN SQLDataSources(SQLHENV henv, SQLUSMALLINT fDirection, SQLCHAR *szDSN, SQLSMALLINT cbDSNMax, SQLSMALLINT *pcbDSN, SQLCHAR *szDescription, SQLSMALLINT cbDescriptionMax, SQLSMALLINT *pcbDescription);
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

    /**
     * @return \FFI\CData SQLCHAR[]
     */
    private static function cString(\FFI $ffi, string $value): \FFI\CData
    {
        $len = \strlen($value);
        $buf = $ffi->new('SQLCHAR['.($len + 1).']');
        if ($len > 0) {
            \FFI::memcpy($buf, $value, $len);
        }
        $buf[$len] = 0;

        return $buf;
    }

    /**
     * @param \FFI\CData $buf SQLCHAR[]
     */
    private static function ffiString(\FFI\CData $buf, ?int $len = null): string
    {
        if (null === $len) {
            $out = '';
            for ($i = 0; ; ++$i) {
                $ch = $buf[$i];
                if (0 === $ch || "\0" === $ch) {
                    break;
                }
                $out .= \is_int($ch) ? \chr($ch) : $ch;
            }

            return $out;
        }
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $buf[$i];
            $out .= \is_int($ch) ? \chr($ch) : $ch;
        }

        return $out;
    }
}

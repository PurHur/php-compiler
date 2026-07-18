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

    public const PGRES_COPY_OUT = 3;

    public const PGRES_COPY_IN = 4;

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

    /**
     * Drain pending results (php-src pg_copy_* pre/post loops).
     */
    public static function drainResults(\FFI\CData $conn): void
    {
        $ffi = self::requireFfi();
        while (null !== ($res = $ffi->PQgetResult($conn))) {
            $ffi->PQclear($res);
        }
    }

    /**
     * @return \FFI\CData|null PGresult*
     */
    public static function getResult(\FFI\CData $conn): ?\FFI\CData
    {
        return self::requireFfi()->PQgetResult($conn);
    }

    /**
     * PQgetCopyData — returns [status, rowString]. status: >0 bytes, -1 done, -2 error, 0 would-block.
     *
     * @return array{0: int, 1: string}
     */
    public static function getCopyData(\FFI\CData $conn): array
    {
        $ffi = self::requireFfi();
        $bufPtr = $ffi->new('char*');
        $ret = (int) $ffi->PQgetCopyData($conn, \FFI::addr($bufPtr), 0);
        if ($ret > 0) {
            $row = \FFI::string($bufPtr, $ret);
            $ffi->PQfreemem($bufPtr);

            return [$ret, $row];
        }

        return [$ret, ''];
    }

    public static function ftable(\FFI\CData $result, int $fieldNum): int
    {
        return (int) self::requireFfi()->PQftable($result, $fieldNum);
    }

    public static function ftype(\FFI\CData $result, int $fieldNum): int
    {
        return (int) self::requireFfi()->PQftype($result, $fieldNum);
    }

    public static function fnumber(\FFI\CData $result, string $fieldName): int
    {
        return (int) self::requireFfi()->PQfnumber($result, $fieldName);
    }

    /**
     * PQescapeStringConn — returns escaped text (no surrounding quotes).
     */
    public static function escapeStringConn(\FFI\CData $conn, string $value): string|false
    {
        $ffi = self::requireFfi();
        $len = \strlen($value);
        $buf = $ffi->new('char['.(($len * 2) + 1).']');
        $err = $ffi->new('int');
        $err->cdata = 0;
        $newLen = (int) $ffi->PQescapeStringConn($conn, $buf, $value, $len, \FFI::addr($err));
        if (0 !== (int) $err->cdata) {
            return false;
        }

        return $newLen > 0 ? \FFI::string($buf, $newLen) : '';
    }

    public static function socket(\FFI\CData $conn): int
    {
        return (int) self::requireFfi()->PQsocket($conn);
    }

    public static function consumeInput(\FFI\CData $conn): bool
    {
        return 1 === (int) self::requireFfi()->PQconsumeInput($conn);
    }

    public static function flush(\FFI\CData $conn): int
    {
        return (int) self::requireFfi()->PQflush($conn);
    }

    public static function isNonBlocking(\FFI\CData $conn): int
    {
        return (int) self::requireFfi()->PQisnonblocking($conn);
    }

    public static function setNonBlocking(\FFI\CData $conn, int $arg): int
    {
        return (int) self::requireFfi()->PQsetnonblocking($conn, $arg);
    }

    /** PQsetErrorVerbosity — previous verbosity (php-src pg_set_error_verbosity; #20660). */
    public static function setErrorVerbosity(\FFI\CData $conn, int $verbosity): int
    {
        return (int) self::requireFfi()->PQsetErrorVerbosity($conn, $verbosity);
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

    /**
     * PQescapeString (no connection) — returns escaped text without quotes.
     */
    public static function escapeString(string $value): string
    {
        $ffi = self::requireFfi();
        $len = \strlen($value);
        $buf = $ffi->new('char['.(($len * 2) + 1).']');
        $newLen = (int) $ffi->PQescapeString($buf, $value, $len);

        return $newLen > 0 ? \FFI::string($buf, $newLen) : '';
    }

    /**
     * PQescapeByteaConn — hex/escape text for bytea literals (includes leading \\x when applicable).
     */
    public static function escapeByteaConn(\FFI\CData $conn, string $value): string
    {
        $ffi = self::requireFfi();
        $from = self::bytesToUnsignedChar($ffi, $value);
        $toLen = $ffi->new('size_t');
        $escaped = $ffi->PQescapeByteaConn($conn, $from, \strlen($value), \FFI::addr($toLen));
        if (null === $escaped) {
            return '';
        }
        $n = (int) $toLen->cdata;
        // libpq includes trailing NUL in to_length
        $out = $n > 0 ? \FFI::string($escaped, \max(0, $n - 1)) : '';
        $ffi->PQfreemem($escaped);

        return $out;
    }

    /** PQescapeBytea without connection. */
    public static function escapeBytea(string $value): string
    {
        $ffi = self::requireFfi();
        $from = self::bytesToUnsignedChar($ffi, $value);
        $toLen = $ffi->new('size_t');
        $escaped = $ffi->PQescapeBytea($from, \strlen($value), \FFI::addr($toLen));
        if (null === $escaped) {
            return '';
        }
        $n = (int) $toLen->cdata;
        $out = $n > 0 ? \FFI::string($escaped, \max(0, $n - 1)) : '';
        $ffi->PQfreemem($escaped);

        return $out;
    }

    /** PQunescapeBytea. */
    public static function unescapeBytea(string $value): string
    {
        $ffi = self::requireFfi();
        $from = self::bytesToUnsignedChar($ffi, $value);
        $toLen = $ffi->new('size_t');
        $raw = $ffi->PQunescapeBytea($from, \FFI::addr($toLen));
        if (null === $raw) {
            return '';
        }
        $out = \FFI::string($raw, (int) $toLen->cdata);
        $ffi->PQfreemem($raw);

        return $out;
    }

    /** @return \FFI\CData unsigned char* */
    private static function bytesToUnsignedChar(\FFI $ffi, string $value): \FFI\CData
    {
        $len = \strlen($value);
        if (0 === $len) {
            $buf = $ffi->new('unsigned char[1]');
            $buf[0] = 0;

            return $ffi->cast('unsigned char*', $buf);
        }
        $buf = $ffi->new('unsigned char['.$len.']');
        \FFI::memcpy($buf, $value, $len);

        return $ffi->cast('unsigned char*', $buf);
    }

    /**
     * @param list<string|null> $params
     *
     * @return \FFI\CData|null PGresult*
     */
    public static function execParams(\FFI\CData $conn, string $query, array $params): ?\FFI\CData
    {
        return self::execWithParams(false, $conn, $query, $params);
    }

    /**
     * @return \FFI\CData|null PGresult*
     */
    public static function prepare(\FFI\CData $conn, string $stmtName, string $query): ?\FFI\CData
    {
        $res = self::requireFfi()->PQprepare($conn, $stmtName, $query, 0, null);

        return null === $res ? null : $res;
    }

    /**
     * @param list<string|null> $params
     *
     * @return \FFI\CData|null PGresult*
     */
    public static function execPrepared(\FFI\CData $conn, string $stmtName, array $params): ?\FFI\CData
    {
        return self::execWithParams(true, $conn, $stmtName, $params);
    }

    public static function cmdTuples(\FFI\CData $result): int
    {
        $raw = self::ffiString(self::requireFfi()->PQcmdTuples($result));
        if ('' === $raw) {
            return 0;
        }

        return (int) $raw;
    }

    /**
     * Shared PQexecParams / PQexecPrepared param marshalling (#20661).
     *
     * @param list<string|null> $params
     *
     * @return \FFI\CData|null PGresult*
     */
    private static function execWithParams(bool $prepared, \FFI\CData $conn, string $commandOrStmt, array $params): ?\FFI\CData
    {
        $ffi = self::requireFfi();
        $n = \count($params);
        $owned = [];
        if ($n > 0) {
            $values = $ffi->new('char*['.$n.']');
            for ($i = 0; $i < $n; ++$i) {
                if (null === $params[$i]) {
                    $values[$i] = null;
                    continue;
                }
                $s = (string) $params[$i];
                $buf = $ffi->new('char['.(\strlen($s) + 1).']', false);
                \FFI::memcpy($buf, $s."\0", \strlen($s) + 1);
                $owned[] = $buf;
                $values[$i] = $ffi->cast('char*', $buf);
            }
        } else {
            $values = null;
        }
        if ($prepared) {
            $res = $ffi->PQexecPrepared($conn, $commandOrStmt, $n, $values, null, null, 0);
        } else {
            $res = $ffi->PQexecParams($conn, $commandOrStmt, $n, null, $values, null, null, 0);
        }
        unset($owned);

        return null === $res ? null : $res;
    }

    /**
     * Enable libpq protocol tracing to a stdio FILE* (php-src pg_trace; #20574).
     *
     * @return \FFI\CData|null FILE* owned by caller (fclose on untrace/close)
     */
    public static function trace(\FFI\CData $conn, string $pathname, string $mode): ?\FFI\CData
    {
        $fp = self::fopen($pathname, $mode);
        if (null === $fp) {
            return null;
        }
        $ffi = self::requireFfi();
        // libc FILE* → libpq FILE* (opaque pointer cast across FFI instances).
        $ffi->PQtrace($conn, $ffi->cast('FILE*', $fp));

        return $fp;
    }

    public static function untrace(\FFI\CData $conn): void
    {
        self::requireFfi()->PQuntrace($conn);
    }

    public const INV_WRITE = 0x00020000;

    public const INV_READ = 0x00040000;

    public const INVALID_OID = 0;

    public static function loCreat(\FFI\CData $conn, int $mode): int
    {
        return (int) self::requireFfi()->lo_creat($conn, $mode);
    }

    public static function loCreate(\FFI\CData $conn, int $oid): int
    {
        return (int) self::requireFfi()->lo_create($conn, $oid);
    }

    public static function loOpen(\FFI\CData $conn, int $oid, int $mode): int
    {
        return (int) self::requireFfi()->lo_open($conn, $oid, $mode);
    }

    public static function loClose(\FFI\CData $conn, int $fd): int
    {
        return (int) self::requireFfi()->lo_close($conn, $fd);
    }

    public static function loRead(\FFI\CData $conn, int $fd, int $length): string|false
    {
        $ffi = self::requireFfi();
        $buf = $ffi->new('char['.$length.']');
        $n = (int) $ffi->lo_read($conn, $fd, $buf, $length);
        if ($n < 0) {
            return false;
        }
        if (0 === $n) {
            return '';
        }

        return \FFI::string($buf, $n);
    }

    public static function loWrite(\FFI\CData $conn, int $fd, string $data, int $length): int
    {
        return (int) self::requireFfi()->lo_write($conn, $fd, $data, $length);
    }

    public static function loLseek(\FFI\CData $conn, int $fd, int $offset, int $whence): int
    {
        return (int) self::requireFfi()->lo_lseek($conn, $fd, $offset, $whence);
    }

    public static function loTell(\FFI\CData $conn, int $fd): int
    {
        return (int) self::requireFfi()->lo_tell($conn, $fd);
    }

    public static function loTruncate(\FFI\CData $conn, int $fd, int $size): int
    {
        return (int) self::requireFfi()->lo_truncate($conn, $fd, $size);
    }

    public static function loUnlink(\FFI\CData $conn, int $oid): int
    {
        return (int) self::requireFfi()->lo_unlink($conn, $oid);
    }

    public static function loImport(\FFI\CData $conn, string $filename): int
    {
        return (int) self::requireFfi()->lo_import($conn, $filename);
    }

    public static function loExport(\FFI\CData $conn, int $oid, string $filename): int
    {
        return (int) self::requireFfi()->lo_export($conn, $oid, $filename);
    }

    public static function fclose(\FFI\CData $fp): void
    {
        $libc = self::libc();
        if (null !== $libc) {
            $libc->fclose($fp);
        }
    }

    /** @return \FFI\CData|null FILE* */
    private static function fopen(string $pathname, string $mode): ?\FFI\CData
    {
        $libc = self::libc();
        if (null === $libc) {
            return null;
        }
        $fp = $libc->fopen($pathname, $mode);
        if (null === $fp) {
            return null;
        }

        return $fp;
    }

    /** @return \FFI|null */
    private static function libc()
    {
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            return null;
        }
        static $libc = false;
        if (false !== $libc) {
            return $libc;
        }
        $cdef = <<<'CDEF'
typedef struct _IO_FILE FILE;
FILE *fopen(const char *pathname, const char *mode);
int fclose(FILE *stream);
CDEF;
        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                $libc = \FFI::cdef($cdef, $lib);

                return $libc;
            } catch (\Throwable) {
            }
        }
        $libc = null;

        return null;
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
typedef struct _IO_FILE FILE;
typedef unsigned int Oid;
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
int PQgetCopyData(PGconn *conn, char **buffer, int async);
PGresult *PQgetResult(PGconn *conn);
int PQsocket(const PGconn *conn);
int PQconsumeInput(PGconn *conn);
int PQflush(PGconn *conn);
int PQisnonblocking(const PGconn *conn);
int PQsetnonblocking(PGconn *conn, int arg);
int PQsetErrorVerbosity(PGconn *conn, int verbosity);
Oid PQftable(const PGresult *res, int field_num);
Oid PQftype(const PGresult *res, int field_num);
int PQfnumber(const PGresult *res, const char *field_name);
size_t PQescapeStringConn(PGconn *conn, char *to, const char *from, size_t length, int *error);
size_t PQescapeString(char *to, const char *from, size_t length);
char *PQescapeIdentifier(PGconn *conn, const char *str, size_t length);
char *PQescapeLiteral(PGconn *conn, const char *str, size_t length);
unsigned char *PQescapeByteaConn(PGconn *conn, const unsigned char *from, size_t from_length, size_t *to_length);
unsigned char *PQescapeBytea(const unsigned char *from, size_t from_length, size_t *to_length);
unsigned char *PQunescapeBytea(const unsigned char *strtext, size_t *retbuflen);
PGresult *PQexecParams(PGconn *conn, const char *command, int nParams, const Oid *paramTypes, const char **paramValues, const int *paramLengths, const int *paramFormats, int resultFormat);
PGresult *PQprepare(PGconn *conn, const char *stmtName, const char *query, int nParams, const Oid *paramTypes);
PGresult *PQexecPrepared(PGconn *conn, const char *stmtName, int nParams, const char **paramValues, const int *paramLengths, const int *paramFormats, int resultFormat);
char *PQcmdTuples(const PGresult *res);
void PQfreemem(void *ptr);
void PQtrace(PGconn *conn, FILE *stream);
void PQuntrace(PGconn *conn);
Oid lo_creat(PGconn *conn, int mode);
Oid lo_create(PGconn *conn, Oid lobjId);
int lo_open(PGconn *conn, Oid lobjId, int mode);
int lo_close(PGconn *conn, int fd);
int lo_read(PGconn *conn, int fd, char *buf, size_t len);
int lo_write(PGconn *conn, int fd, const char *buf, size_t len);
int lo_lseek(PGconn *conn, int fd, int offset, int whence);
int lo_tell(PGconn *conn, int fd);
int lo_truncate(PGconn *conn, int fd, size_t len);
int lo_unlink(PGconn *conn, Oid lobjId);
Oid lo_import(PGconn *conn, const char *filename);
int lo_export(PGconn *conn, Oid lobjId, const char *filename);
CDEF;

        foreach ([
            'libpq.so.5',
            'libpq.so',
            '/usr/lib/x86_64-linux-gnu/libpq.so.5',
            '/usr/lib/x86_64-linux-gnu/libpq.so',
        ] as $lib) {
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

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

/**
 * ext/pgsql constants (php-src ext/pgsql/php_pgsql.h + pgsql.c; #20637).
 */
final class PgsqlConstants
{
    public const PGSQL_ASSOC = 1;

    public const PGSQL_NUM = 2;

    public const PGSQL_BOTH = 3;

    /** libpq PGRES_EMPTY_QUERY (php-src pgsql.stub.php; #24129) */
    public const PGSQL_EMPTY_QUERY = 0;

    /** libpq PGRES_COMMAND_OK */
    public const PGSQL_COMMAND_OK = 1;

    /** libpq PGRES_TUPLES_OK */
    public const PGSQL_TUPLES_OK = 2;

    /** libpq PGRES_COPY_OUT */
    public const PGSQL_COPY_OUT = 3;

    /** libpq PGRES_COPY_IN */
    public const PGSQL_COPY_IN = 4;

    /** libpq PGRES_BAD_RESPONSE */
    public const PGSQL_BAD_RESPONSE = 5;

    /** libpq PGRES_NONFATAL_ERROR */
    public const PGSQL_NONFATAL_ERROR = 6;

    /** libpq PGRES_FATAL_ERROR */
    public const PGSQL_FATAL_ERROR = 7;

    /** php-src PGSQL_SEEK_SET — large-object seek origin (#24129) */
    public const PGSQL_SEEK_SET = 0;

    /** php-src PGSQL_SEEK_CUR */
    public const PGSQL_SEEK_CUR = 1;

    /** php-src PGSQL_SEEK_END */
    public const PGSQL_SEEK_END = 2;

    public const PGSQL_CONV_IGNORE_DEFAULT = 1 << 1;

    public const PGSQL_CONV_FORCE_NULL = 1 << 2;

    public const PGSQL_CONV_IGNORE_NOT_NULL = 1 << 3;

    public const PGSQL_DML_NO_CONV = 1 << 8;

    public const PGSQL_DML_EXEC = 1 << 9;

    public const PGSQL_DML_ASYNC = 1 << 10;

    public const PGSQL_DML_STRING = 1 << 11;

    public const PGSQL_DML_ESCAPE = 1 << 12;

    /** libpq PQERRORS_TERSE */
    public const PGSQL_ERRORS_TERSE = 0;

    /** libpq PQERRORS_DEFAULT */
    public const PGSQL_ERRORS_DEFAULT = 1;

    /** libpq PQERRORS_VERBOSE */
    public const PGSQL_ERRORS_VERBOSE = 2;

    /** libpq PQERRORS_SQLSTATE (PHP 8.3+) */
    public const PGSQL_ERRORS_SQLSTATE = 3;

    /** libpq PQSHOW_CONTEXT_NEVER */
    public const PGSQL_SHOW_CONTEXT_NEVER = 0;

    /** libpq PQSHOW_CONTEXT_ERRORS */
    public const PGSQL_SHOW_CONTEXT_ERRORS = 1;

    /** libpq PQSHOW_CONTEXT_ALWAYS */
    public const PGSQL_SHOW_CONTEXT_ALWAYS = 2;

    /** libpq CONNECTION_OK */
    public const PGSQL_CONNECTION_OK = 0;

    /** libpq CONNECTION_BAD */
    public const PGSQL_CONNECTION_BAD = 1;

    /** libpq CONNECTION_STARTED */
    public const PGSQL_CONNECTION_STARTED = 2;

    /** libpq CONNECTION_MADE */
    public const PGSQL_CONNECTION_MADE = 3;

    /** libpq CONNECTION_AWAITING_RESPONSE */
    public const PGSQL_CONNECTION_AWAITING_RESPONSE = 4;

    /** libpq CONNECTION_AUTH_OK */
    public const PGSQL_CONNECTION_AUTH_OK = 5;

    /** libpq CONNECTION_SETENV */
    public const PGSQL_CONNECTION_SETENV = 6;

    /** libpq CONNECTION_SSL_STARTUP */
    public const PGSQL_CONNECTION_SSL_STARTUP = 7;

    /** php-src PGSQL_CONNECT_FORCE_NEW (1<<1) */
    public const PGSQL_CONNECT_FORCE_NEW = 1 << 1;

    /** php-src PGSQL_CONNECT_ASYNC (1<<2) — PQconnectStart + pg_connect_poll */
    public const PGSQL_CONNECT_ASYNC = 1 << 2;

    /** libpq PGRES_POLLING_FAILED */
    public const PGSQL_POLLING_FAILED = 0;

    /** libpq PGRES_POLLING_READING */
    public const PGSQL_POLLING_READING = 1;

    /** libpq PGRES_POLLING_WRITING */
    public const PGSQL_POLLING_WRITING = 2;

    /** libpq PGRES_POLLING_OK */
    public const PGSQL_POLLING_OK = 3;

    /** libpq PGRES_POLLING_ACTIVE (compat) */
    public const PGSQL_POLLING_ACTIVE = 4;

    /** libpq PQTRANS_IDLE */
    public const PGSQL_TRANSACTION_IDLE = 0;

    /** libpq PQTRANS_ACTIVE */
    public const PGSQL_TRANSACTION_ACTIVE = 1;

    /** libpq PQTRANS_INTRANS */
    public const PGSQL_TRANSACTION_INTRANS = 2;

    /** libpq PQTRANS_INERROR */
    public const PGSQL_TRANSACTION_INERROR = 3;

    /** libpq PQTRANS_UNKNOWN */
    public const PGSQL_TRANSACTION_UNKNOWN = 4;

    /** php-src PGSQL_STATUS_LONG — return ExecStatusType as int */
    public const PGSQL_STATUS_LONG = 1;

    /** php-src PGSQL_STATUS_STRING — return PQcmdStatus string */
    public const PGSQL_STATUS_STRING = 2;

    /** libpq PG_DIAG_SEVERITY ('S') */
    public const PGSQL_DIAG_SEVERITY = 83;

    /** libpq PG_DIAG_SQLSTATE ('C') */
    public const PGSQL_DIAG_SQLSTATE = 67;

    /** libpq PG_DIAG_MESSAGE_PRIMARY ('M') */
    public const PGSQL_DIAG_MESSAGE_PRIMARY = 77;

    /** libpq PG_DIAG_MESSAGE_DETAIL ('D') */
    public const PGSQL_DIAG_MESSAGE_DETAIL = 68;

    /** libpq PG_DIAG_MESSAGE_HINT ('H') */
    public const PGSQL_DIAG_MESSAGE_HINT = 72;

    /** libpq PG_DIAG_STATEMENT_POSITION ('P') */
    public const PGSQL_DIAG_STATEMENT_POSITION = 80;

    /** libpq PG_DIAG_INTERNAL_POSITION ('p') */
    public const PGSQL_DIAG_INTERNAL_POSITION = 112;

    /** libpq PG_DIAG_INTERNAL_QUERY ('q') */
    public const PGSQL_DIAG_INTERNAL_QUERY = 113;

    /** libpq PG_DIAG_CONTEXT ('W') */
    public const PGSQL_DIAG_CONTEXT = 87;

    /** libpq PG_DIAG_SOURCE_FILE ('F') */
    public const PGSQL_DIAG_SOURCE_FILE = 70;

    /** libpq PG_DIAG_SOURCE_LINE ('L') */
    public const PGSQL_DIAG_SOURCE_LINE = 76;

    /** libpq PG_DIAG_SOURCE_FUNCTION ('R') */
    public const PGSQL_DIAG_SOURCE_FUNCTION = 82;

    /** libpq PG_DIAG_SCHEMA_NAME ('s') */
    public const PGSQL_DIAG_SCHEMA_NAME = 115;

    /** libpq PG_DIAG_TABLE_NAME ('t') */
    public const PGSQL_DIAG_TABLE_NAME = 116;

    /** libpq PG_DIAG_COLUMN_NAME ('c') */
    public const PGSQL_DIAG_COLUMN_NAME = 99;

    /** libpq PG_DIAG_DATATYPE_NAME ('d') */
    public const PGSQL_DIAG_DATATYPE_NAME = 100;

    /** libpq PG_DIAG_CONSTRAINT_NAME ('n') */
    public const PGSQL_DIAG_CONSTRAINT_NAME = 110;

    /** libpq PG_DIAG_SEVERITY_NONLOCALIZED ('V') */
    public const PGSQL_DIAG_SEVERITY_NONLOCALIZED = 86;

    /** php-src PGSQL_NOTICE_LAST — last notice string (#22217) */
    public const PGSQL_NOTICE_LAST = 1;

    /** php-src PGSQL_NOTICE_ALL — all notices as array (#22217) */
    public const PGSQL_NOTICE_ALL = 2;

    /** php-src PGSQL_NOTICE_CLEAR — clear notice buffer (#22217) */
    public const PGSQL_NOTICE_CLEAR = 3;

    /**
     * @return array<string, int|string>
     */
    public static function registeredConstants(): array
    {
        return [
            'PGSQL_ASSOC' => self::PGSQL_ASSOC,
            'PGSQL_NUM' => self::PGSQL_NUM,
            'PGSQL_BOTH' => self::PGSQL_BOTH,
            'PGSQL_EMPTY_QUERY' => self::PGSQL_EMPTY_QUERY,
            'PGSQL_COMMAND_OK' => self::PGSQL_COMMAND_OK,
            'PGSQL_TUPLES_OK' => self::PGSQL_TUPLES_OK,
            'PGSQL_COPY_OUT' => self::PGSQL_COPY_OUT,
            'PGSQL_COPY_IN' => self::PGSQL_COPY_IN,
            'PGSQL_BAD_RESPONSE' => self::PGSQL_BAD_RESPONSE,
            'PGSQL_NONFATAL_ERROR' => self::PGSQL_NONFATAL_ERROR,
            'PGSQL_FATAL_ERROR' => self::PGSQL_FATAL_ERROR,
            'PGSQL_SEEK_SET' => self::PGSQL_SEEK_SET,
            'PGSQL_SEEK_CUR' => self::PGSQL_SEEK_CUR,
            'PGSQL_SEEK_END' => self::PGSQL_SEEK_END,
            ...self::libpqVersionConstants(),
            'PGSQL_CONV_IGNORE_DEFAULT' => self::PGSQL_CONV_IGNORE_DEFAULT,
            'PGSQL_CONV_FORCE_NULL' => self::PGSQL_CONV_FORCE_NULL,
            'PGSQL_CONV_IGNORE_NOT_NULL' => self::PGSQL_CONV_IGNORE_NOT_NULL,
            'PGSQL_DML_NO_CONV' => self::PGSQL_DML_NO_CONV,
            'PGSQL_DML_EXEC' => self::PGSQL_DML_EXEC,
            'PGSQL_DML_ASYNC' => self::PGSQL_DML_ASYNC,
            'PGSQL_DML_STRING' => self::PGSQL_DML_STRING,
            'PGSQL_DML_ESCAPE' => self::PGSQL_DML_ESCAPE,
            'PGSQL_ERRORS_TERSE' => self::PGSQL_ERRORS_TERSE,
            'PGSQL_ERRORS_DEFAULT' => self::PGSQL_ERRORS_DEFAULT,
            'PGSQL_ERRORS_VERBOSE' => self::PGSQL_ERRORS_VERBOSE,
            'PGSQL_ERRORS_SQLSTATE' => self::PGSQL_ERRORS_SQLSTATE,
            ...self::php83ShowContextConstants(),
            'PGSQL_CONNECTION_OK' => self::PGSQL_CONNECTION_OK,
            'PGSQL_CONNECTION_BAD' => self::PGSQL_CONNECTION_BAD,
            'PGSQL_CONNECTION_STARTED' => self::PGSQL_CONNECTION_STARTED,
            'PGSQL_CONNECTION_MADE' => self::PGSQL_CONNECTION_MADE,
            'PGSQL_CONNECTION_AWAITING_RESPONSE' => self::PGSQL_CONNECTION_AWAITING_RESPONSE,
            'PGSQL_CONNECTION_AUTH_OK' => self::PGSQL_CONNECTION_AUTH_OK,
            'PGSQL_CONNECTION_SETENV' => self::PGSQL_CONNECTION_SETENV,
            'PGSQL_CONNECTION_SSL_STARTUP' => self::PGSQL_CONNECTION_SSL_STARTUP,
            'PGSQL_CONNECT_FORCE_NEW' => self::PGSQL_CONNECT_FORCE_NEW,
            'PGSQL_CONNECT_ASYNC' => self::PGSQL_CONNECT_ASYNC,
            'PGSQL_POLLING_FAILED' => self::PGSQL_POLLING_FAILED,
            'PGSQL_POLLING_READING' => self::PGSQL_POLLING_READING,
            'PGSQL_POLLING_WRITING' => self::PGSQL_POLLING_WRITING,
            'PGSQL_POLLING_OK' => self::PGSQL_POLLING_OK,
            'PGSQL_POLLING_ACTIVE' => self::PGSQL_POLLING_ACTIVE,
            'PGSQL_TRANSACTION_IDLE' => self::PGSQL_TRANSACTION_IDLE,
            'PGSQL_TRANSACTION_ACTIVE' => self::PGSQL_TRANSACTION_ACTIVE,
            'PGSQL_TRANSACTION_INTRANS' => self::PGSQL_TRANSACTION_INTRANS,
            'PGSQL_TRANSACTION_INERROR' => self::PGSQL_TRANSACTION_INERROR,
            'PGSQL_TRANSACTION_UNKNOWN' => self::PGSQL_TRANSACTION_UNKNOWN,
            'PGSQL_STATUS_LONG' => self::PGSQL_STATUS_LONG,
            'PGSQL_STATUS_STRING' => self::PGSQL_STATUS_STRING,
            'PGSQL_NOTICE_LAST' => self::PGSQL_NOTICE_LAST,
            'PGSQL_NOTICE_ALL' => self::PGSQL_NOTICE_ALL,
            'PGSQL_NOTICE_CLEAR' => self::PGSQL_NOTICE_CLEAR,
            'PGSQL_DIAG_SEVERITY' => self::PGSQL_DIAG_SEVERITY,
            'PGSQL_DIAG_SQLSTATE' => self::PGSQL_DIAG_SQLSTATE,
            'PGSQL_DIAG_MESSAGE_PRIMARY' => self::PGSQL_DIAG_MESSAGE_PRIMARY,
            'PGSQL_DIAG_MESSAGE_DETAIL' => self::PGSQL_DIAG_MESSAGE_DETAIL,
            'PGSQL_DIAG_MESSAGE_HINT' => self::PGSQL_DIAG_MESSAGE_HINT,
            'PGSQL_DIAG_STATEMENT_POSITION' => self::PGSQL_DIAG_STATEMENT_POSITION,
            'PGSQL_DIAG_INTERNAL_POSITION' => self::PGSQL_DIAG_INTERNAL_POSITION,
            'PGSQL_DIAG_INTERNAL_QUERY' => self::PGSQL_DIAG_INTERNAL_QUERY,
            'PGSQL_DIAG_CONTEXT' => self::PGSQL_DIAG_CONTEXT,
            'PGSQL_DIAG_SOURCE_FILE' => self::PGSQL_DIAG_SOURCE_FILE,
            'PGSQL_DIAG_SOURCE_LINE' => self::PGSQL_DIAG_SOURCE_LINE,
            'PGSQL_DIAG_SOURCE_FUNCTION' => self::PGSQL_DIAG_SOURCE_FUNCTION,
            'PGSQL_DIAG_SCHEMA_NAME' => self::PGSQL_DIAG_SCHEMA_NAME,
            'PGSQL_DIAG_TABLE_NAME' => self::PGSQL_DIAG_TABLE_NAME,
            'PGSQL_DIAG_COLUMN_NAME' => self::PGSQL_DIAG_COLUMN_NAME,
            'PGSQL_DIAG_DATATYPE_NAME' => self::PGSQL_DIAG_DATATYPE_NAME,
            'PGSQL_DIAG_CONSTRAINT_NAME' => self::PGSQL_DIAG_CONSTRAINT_NAME,
            'PGSQL_DIAG_SEVERITY_NONLOCALIZED' => self::PGSQL_DIAG_SEVERITY_NONLOCALIZED,
        ];
    }

    /**
     * PHP 8.3+ PGSQL_SHOW_CONTEXT_* (#20674 / #22620).
     *
     * @return array<string, int>
     */
    private static function php83ShowContextConstants(): array
    {
        if (!PgsqlExtensionPolicy::advertisesPhp83ErrorContextVisibility()) {
            return [];
        }

        return [
            'PGSQL_SHOW_CONTEXT_NEVER' => self::PGSQL_SHOW_CONTEXT_NEVER,
            'PGSQL_SHOW_CONTEXT_ERRORS' => self::PGSQL_SHOW_CONTEXT_ERRORS,
            'PGSQL_SHOW_CONTEXT_ALWAYS' => self::PGSQL_SHOW_CONTEXT_ALWAYS,
        ];
    }

    /**
     * PGSQL_LIBPQ_VERSION / _STR — php-src php_pgsql_minit via php_libpq_version (#24129).
     *
     * @return array<string, string>
     */
    private static function libpqVersionConstants(): array
    {
        $version = self::resolveLibpqVersionString();

        return [
            'PGSQL_LIBPQ_VERSION' => $version,
            'PGSQL_LIBPQ_VERSION_STR' => $version,
        ];
    }

    /** Prefer live libpq FFI; fall back to host Zend when FFI unavailable. */
    private static function resolveLibpqVersionString(): string
    {
        if (VmPgsqlNative::available()) {
            return VmPgsqlNative::libpqVersionString();
        }
        if (\defined('PGSQL_LIBPQ_VERSION')) {
            return (string) \constant('PGSQL_LIBPQ_VERSION');
        }

        return '0.0';
    }
}

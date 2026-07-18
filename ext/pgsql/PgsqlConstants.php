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

    /**
     * @return array<string, int>
     */
    public static function registeredConstants(): array
    {
        return [
            'PGSQL_ASSOC' => self::PGSQL_ASSOC,
            'PGSQL_NUM' => self::PGSQL_NUM,
            'PGSQL_BOTH' => self::PGSQL_BOTH,
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
            'PGSQL_SHOW_CONTEXT_NEVER' => self::PGSQL_SHOW_CONTEXT_NEVER,
            'PGSQL_SHOW_CONTEXT_ERRORS' => self::PGSQL_SHOW_CONTEXT_ERRORS,
            'PGSQL_SHOW_CONTEXT_ALWAYS' => self::PGSQL_SHOW_CONTEXT_ALWAYS,
            'PGSQL_CONNECTION_OK' => self::PGSQL_CONNECTION_OK,
            'PGSQL_CONNECTION_BAD' => self::PGSQL_CONNECTION_BAD,
            'PGSQL_CONNECTION_STARTED' => self::PGSQL_CONNECTION_STARTED,
            'PGSQL_CONNECTION_MADE' => self::PGSQL_CONNECTION_MADE,
            'PGSQL_CONNECTION_AWAITING_RESPONSE' => self::PGSQL_CONNECTION_AWAITING_RESPONSE,
            'PGSQL_CONNECTION_AUTH_OK' => self::PGSQL_CONNECTION_AUTH_OK,
            'PGSQL_CONNECTION_SETENV' => self::PGSQL_CONNECTION_SETENV,
            'PGSQL_CONNECTION_SSL_STARTUP' => self::PGSQL_CONNECTION_SSL_STARTUP,
            'PGSQL_TRANSACTION_IDLE' => self::PGSQL_TRANSACTION_IDLE,
            'PGSQL_TRANSACTION_ACTIVE' => self::PGSQL_TRANSACTION_ACTIVE,
            'PGSQL_TRANSACTION_INTRANS' => self::PGSQL_TRANSACTION_INTRANS,
            'PGSQL_TRANSACTION_INERROR' => self::PGSQL_TRANSACTION_INERROR,
            'PGSQL_TRANSACTION_UNKNOWN' => self::PGSQL_TRANSACTION_UNKNOWN,
            'PGSQL_STATUS_LONG' => self::PGSQL_STATUS_LONG,
            'PGSQL_STATUS_STRING' => self::PGSQL_STATUS_STRING,
        ];
    }
}

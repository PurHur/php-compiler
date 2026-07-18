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
        ];
    }
}

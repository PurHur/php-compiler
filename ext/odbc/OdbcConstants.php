<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

/**
 * ODBC constants (php-src ext/odbc/php_odbc.stub.php; #6293).
 */
final class OdbcConstants
{
    public const SQL_CUR_USE_IF_NEEDED = 0;

    public const SQL_CUR_USE_ODBC = 1;

    public const SQL_CUR_USE_DRIVER = 2;

    public const SQL_CUR_DEFAULT = -1;

    /** SQL_FETCH_NEXT — odbc_data_source() (sqlext.h). */
    public const SQL_FETCH_NEXT = 1;

    /** SQL_FETCH_FIRST — odbc_data_source() (sqlext.h). */
    public const SQL_FETCH_FIRST = 2;

    /**
     * @return array<string, int>
     */
    public static function registeredConstants(): array
    {
        return [
            'SQL_CUR_USE_IF_NEEDED' => self::SQL_CUR_USE_IF_NEEDED,
            'SQL_CUR_USE_ODBC' => self::SQL_CUR_USE_ODBC,
            'SQL_CUR_USE_DRIVER' => self::SQL_CUR_USE_DRIVER,
            'SQL_CUR_DEFAULT' => self::SQL_CUR_DEFAULT,
            'SQL_FETCH_NEXT' => self::SQL_FETCH_NEXT,
            'SQL_FETCH_FIRST' => self::SQL_FETCH_FIRST,
        ];
    }
}

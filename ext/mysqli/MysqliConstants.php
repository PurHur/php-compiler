<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

/**
 * ext/mysqli constants — php-src ext/mysqli/mysqli.c (#3435).
 *
 * Subset: report modes, fetch modes, result types used by procedural API v1.
 */
final class MysqliConstants
{
    public const MYSQLI_REPORT_OFF = 0;
    public const MYSQLI_REPORT_ERROR = 1;
    public const MYSQLI_REPORT_STRICT = 2;
    public const MYSQLI_REPORT_INDEX = 4;
    public const MYSQLI_REPORT_ALL = 255;

    public const MYSQLI_ASSOC = 1;
    public const MYSQLI_NUM = 2;
    public const MYSQLI_BOTH = 3;

    public const MYSQLI_STORE_RESULT = 0;
    public const MYSQLI_USE_RESULT = 1;
    /** Non-blocking query mode — php-src mysqli_mysqlnd.h MYSQLI_ASYNC (#22163). */
    public const MYSQLI_ASYNC = 128;

    public const MYSQLI_CLIENT_COMPRESS = 32;
    public const MYSQLI_CLIENT_SSL = 2048;
    public const MYSQLI_CLIENT_INTERACTIVE = 1024;

    public const MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT = 1;
    public const MYSQLI_TRANS_START_READ_WRITE = 2;
    public const MYSQLI_TRANS_START_READ_ONLY = 4;

    public const MYSQLI_REFRESH_GRANT = 1;
    public const MYSQLI_REFRESH_LOG = 2;
    public const MYSQLI_REFRESH_TABLES = 4;
    public const MYSQLI_REFRESH_HOSTS = 8;
    public const MYSQLI_REFRESH_STATUS = 16;
    public const MYSQLI_REFRESH_THREADS = 32;
    public const MYSQLI_REFRESH_SLAVE = 64;
    public const MYSQLI_REFRESH_MASTER = 128;
    public const MYSQLI_REFRESH_BACKUP_LOG = 2097152;

    public const MYSQLI_OPT_CONNECT_TIMEOUT = 0;
    public const MYSQLI_INIT_COMMAND = 3;
    public const MYSQLI_READ_DEFAULT_FILE = 4;
    public const MYSQLI_READ_DEFAULT_GROUP = 5;
    public const MYSQLI_OPT_LOCAL_INFILE = 8;
    public const MYSQLI_OPT_READ_TIMEOUT = 11;
    public const MYSQLI_OPT_NET_CMD_BUFFER_SIZE = 12;
    public const MYSQLI_OPT_NET_READ_BUFFER_SIZE = 13;
    public const MYSQLI_OPT_INT_AND_FLOAT_NATIVE = 17;
    public const MYSQLI_OPT_SSL_VERIFY_SERVER_CERT = 21;

    /**
     * Statement attributes — php-src ext/mysqlnd/mysqlnd_enum_n_def.h enum mysqlnd_stmt_attr (#22175).
     * PHP 8.2 profile includes PREFETCH_ROWS (removed in php-src 8.4+).
     */
    public const MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH = 0;
    public const MYSQLI_STMT_ATTR_CURSOR_TYPE = 1;
    public const MYSQLI_STMT_ATTR_PREFETCH_ROWS = 2;

    public const MYSQLI_CURSOR_TYPE_NO_CURSOR = 0;
    public const MYSQLI_CURSOR_TYPE_READ_ONLY = 1;
    public const MYSQLI_CURSOR_TYPE_FOR_UPDATE = 2;
    public const MYSQLI_CURSOR_TYPE_SCROLLABLE = 4;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'MYSQLI_REPORT_OFF' => self::MYSQLI_REPORT_OFF,
            'MYSQLI_REPORT_ERROR' => self::MYSQLI_REPORT_ERROR,
            'MYSQLI_REPORT_STRICT' => self::MYSQLI_REPORT_STRICT,
            'MYSQLI_REPORT_INDEX' => self::MYSQLI_REPORT_INDEX,
            'MYSQLI_REPORT_ALL' => self::MYSQLI_REPORT_ALL,
            'MYSQLI_ASSOC' => self::MYSQLI_ASSOC,
            'MYSQLI_NUM' => self::MYSQLI_NUM,
            'MYSQLI_BOTH' => self::MYSQLI_BOTH,
            'MYSQLI_STORE_RESULT' => self::MYSQLI_STORE_RESULT,
            'MYSQLI_USE_RESULT' => self::MYSQLI_USE_RESULT,
            'MYSQLI_ASYNC' => self::MYSQLI_ASYNC,
            'MYSQLI_CLIENT_COMPRESS' => self::MYSQLI_CLIENT_COMPRESS,
            'MYSQLI_CLIENT_SSL' => self::MYSQLI_CLIENT_SSL,
            'MYSQLI_CLIENT_INTERACTIVE' => self::MYSQLI_CLIENT_INTERACTIVE,
            'MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT' => self::MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT,
            'MYSQLI_TRANS_START_READ_WRITE' => self::MYSQLI_TRANS_START_READ_WRITE,
            'MYSQLI_TRANS_START_READ_ONLY' => self::MYSQLI_TRANS_START_READ_ONLY,
            'MYSQLI_REFRESH_GRANT' => self::MYSQLI_REFRESH_GRANT,
            'MYSQLI_REFRESH_LOG' => self::MYSQLI_REFRESH_LOG,
            'MYSQLI_REFRESH_TABLES' => self::MYSQLI_REFRESH_TABLES,
            'MYSQLI_REFRESH_HOSTS' => self::MYSQLI_REFRESH_HOSTS,
            'MYSQLI_REFRESH_STATUS' => self::MYSQLI_REFRESH_STATUS,
            'MYSQLI_REFRESH_THREADS' => self::MYSQLI_REFRESH_THREADS,
            'MYSQLI_REFRESH_SLAVE' => self::MYSQLI_REFRESH_SLAVE,
            'MYSQLI_REFRESH_MASTER' => self::MYSQLI_REFRESH_MASTER,
            'MYSQLI_REFRESH_BACKUP_LOG' => self::MYSQLI_REFRESH_BACKUP_LOG,
            'MYSQLI_OPT_CONNECT_TIMEOUT' => self::MYSQLI_OPT_CONNECT_TIMEOUT,
            'MYSQLI_INIT_COMMAND' => self::MYSQLI_INIT_COMMAND,
            'MYSQLI_READ_DEFAULT_FILE' => self::MYSQLI_READ_DEFAULT_FILE,
            'MYSQLI_READ_DEFAULT_GROUP' => self::MYSQLI_READ_DEFAULT_GROUP,
            'MYSQLI_OPT_LOCAL_INFILE' => self::MYSQLI_OPT_LOCAL_INFILE,
            'MYSQLI_OPT_READ_TIMEOUT' => self::MYSQLI_OPT_READ_TIMEOUT,
            'MYSQLI_OPT_NET_CMD_BUFFER_SIZE' => self::MYSQLI_OPT_NET_CMD_BUFFER_SIZE,
            'MYSQLI_OPT_NET_READ_BUFFER_SIZE' => self::MYSQLI_OPT_NET_READ_BUFFER_SIZE,
            'MYSQLI_OPT_INT_AND_FLOAT_NATIVE' => self::MYSQLI_OPT_INT_AND_FLOAT_NATIVE,
            'MYSQLI_OPT_SSL_VERIFY_SERVER_CERT' => self::MYSQLI_OPT_SSL_VERIFY_SERVER_CERT,
            'MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH' => self::MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH,
            'MYSQLI_STMT_ATTR_CURSOR_TYPE' => self::MYSQLI_STMT_ATTR_CURSOR_TYPE,
            'MYSQLI_STMT_ATTR_PREFETCH_ROWS' => self::MYSQLI_STMT_ATTR_PREFETCH_ROWS,
            'MYSQLI_CURSOR_TYPE_NO_CURSOR' => self::MYSQLI_CURSOR_TYPE_NO_CURSOR,
            'MYSQLI_CURSOR_TYPE_READ_ONLY' => self::MYSQLI_CURSOR_TYPE_READ_ONLY,
            'MYSQLI_CURSOR_TYPE_FOR_UPDATE' => self::MYSQLI_CURSOR_TYPE_FOR_UPDATE,
            'MYSQLI_CURSOR_TYPE_SCROLLABLE' => self::MYSQLI_CURSOR_TYPE_SCROLLABLE,
        ];
    }

    public const CLASS_CONSTANTS = [
        'REPORT_OFF' => self::MYSQLI_REPORT_OFF,
        'REPORT_ERROR' => self::MYSQLI_REPORT_ERROR,
        'REPORT_STRICT' => self::MYSQLI_REPORT_STRICT,
        'REPORT_INDEX' => self::MYSQLI_REPORT_INDEX,
        'REPORT_ALL' => self::MYSQLI_REPORT_ALL,
    ];

    public const CLASS_CONSTANT_NAMES = [
        'REPORT_OFF' => 'REPORT_OFF',
        'REPORT_ERROR' => 'REPORT_ERROR',
        'REPORT_STRICT' => 'REPORT_STRICT',
        'REPORT_INDEX' => 'REPORT_INDEX',
        'REPORT_ALL' => 'REPORT_ALL',
    ];
}

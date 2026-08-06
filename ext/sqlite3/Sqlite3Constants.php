<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

/**
 * SQLite3 class constants (php-src ext/sqlite3/sqlite3.stub.php; issue #3434 / #20683).
 */
final class Sqlite3Constants
{
    public const OPEN_READONLY = 1;
    public const OPEN_READWRITE = 2;
    public const OPEN_CREATE = 4;
    public const ASSOC = 1;
    public const NUM = 2;
    public const BOTH = 3;

    /** SQLite3Stmt::EXPLAIN_MODE_* (php-src; SQLite ≥ 3.43; #20600). */
    public const EXPLAIN_MODE_PREPARED = 0;
    public const EXPLAIN_MODE_EXPLAIN = 1;
    public const EXPLAIN_MODE_EXPLAIN_QUERY_PLAN = 2;

    /** Authorizer return codes (sqlite3.h; #20683). */
    public const OK = 0;
    public const DENY = 1;
    public const IGNORE = 2;
    public const COPY = 0;
    public const CREATE_INDEX = 1;
    public const CREATE_TABLE = 2;
    public const CREATE_TEMP_INDEX = 3;
    public const CREATE_TEMP_TABLE = 4;
    public const CREATE_TEMP_TRIGGER = 5;
    public const CREATE_TEMP_VIEW = 6;
    public const CREATE_TRIGGER = 7;
    public const CREATE_VIEW = 8;
    public const DELETE = 9;
    public const DROP_INDEX = 10;
    public const DROP_TABLE = 11;
    public const DROP_TEMP_INDEX = 12;
    public const DROP_TEMP_TABLE = 13;
    public const DROP_TEMP_TRIGGER = 14;
    public const DROP_TEMP_VIEW = 15;
    public const DROP_TRIGGER = 16;
    public const DROP_VIEW = 17;
    public const INSERT = 18;
    public const PRAGMA = 19;
    public const READ = 20;
    public const SELECT = 21;
    public const TRANSACTION = 22;
    public const UPDATE = 23;
    public const ATTACH = 24;
    public const DETACH = 25;
    public const ALTER_TABLE = 26;
    public const REINDEX = 27;
    public const ANALYZE = 28;
    public const CREATE_VTABLE = 29;
    public const DROP_VTABLE = 30;
    public const FUNCTION = 31;
    public const SAVEPOINT = 32;
    public const RECURSIVE = 33;

    /** @var array<string, int> */
    public const CLASS_CONSTANTS = [
        'OPEN_READONLY' => self::OPEN_READONLY,
        'OPEN_READWRITE' => self::OPEN_READWRITE,
        'OPEN_CREATE' => self::OPEN_CREATE,
        'ASSOC' => self::ASSOC,
        'NUM' => self::NUM,
        'BOTH' => self::BOTH,
        'OK' => self::OK,
        'DENY' => self::DENY,
        'IGNORE' => self::IGNORE,
        'COPY' => self::COPY,
        'CREATE_INDEX' => self::CREATE_INDEX,
        'CREATE_TABLE' => self::CREATE_TABLE,
        'CREATE_TEMP_INDEX' => self::CREATE_TEMP_INDEX,
        'CREATE_TEMP_TABLE' => self::CREATE_TEMP_TABLE,
        'CREATE_TEMP_TRIGGER' => self::CREATE_TEMP_TRIGGER,
        'CREATE_TEMP_VIEW' => self::CREATE_TEMP_VIEW,
        'CREATE_TRIGGER' => self::CREATE_TRIGGER,
        'CREATE_VIEW' => self::CREATE_VIEW,
        'DELETE' => self::DELETE,
        'DROP_INDEX' => self::DROP_INDEX,
        'DROP_TABLE' => self::DROP_TABLE,
        'DROP_TEMP_INDEX' => self::DROP_TEMP_INDEX,
        'DROP_TEMP_TABLE' => self::DROP_TEMP_TABLE,
        'DROP_TEMP_TRIGGER' => self::DROP_TEMP_TRIGGER,
        'DROP_TEMP_VIEW' => self::DROP_TEMP_VIEW,
        'DROP_TRIGGER' => self::DROP_TRIGGER,
        'DROP_VIEW' => self::DROP_VIEW,
        'INSERT' => self::INSERT,
        'PRAGMA' => self::PRAGMA,
        'READ' => self::READ,
        'SELECT' => self::SELECT,
        'TRANSACTION' => self::TRANSACTION,
        'UPDATE' => self::UPDATE,
        'ATTACH' => self::ATTACH,
        'DETACH' => self::DETACH,
        'ALTER_TABLE' => self::ALTER_TABLE,
        'REINDEX' => self::REINDEX,
        'ANALYZE' => self::ANALYZE,
        'CREATE_VTABLE' => self::CREATE_VTABLE,
        'DROP_VTABLE' => self::DROP_VTABLE,
        'FUNCTION' => self::FUNCTION,
        'SAVEPOINT' => self::SAVEPOINT,
        'RECURSIVE' => self::RECURSIVE,
    ];

    /** @var array<string, string> */
    public const CLASS_CONSTANT_NAMES = [
        'OPEN_READONLY' => 'OPEN_READONLY',
        'OPEN_READWRITE' => 'OPEN_READWRITE',
        'OPEN_CREATE' => 'OPEN_CREATE',
        'ASSOC' => 'ASSOC',
        'NUM' => 'NUM',
        'BOTH' => 'BOTH',
        'OK' => 'OK',
        'DENY' => 'DENY',
        'IGNORE' => 'IGNORE',
        'COPY' => 'COPY',
        'CREATE_INDEX' => 'CREATE_INDEX',
        'CREATE_TABLE' => 'CREATE_TABLE',
        'CREATE_TEMP_INDEX' => 'CREATE_TEMP_INDEX',
        'CREATE_TEMP_TABLE' => 'CREATE_TEMP_TABLE',
        'CREATE_TEMP_TRIGGER' => 'CREATE_TEMP_TRIGGER',
        'CREATE_TEMP_VIEW' => 'CREATE_TEMP_VIEW',
        'CREATE_TRIGGER' => 'CREATE_TRIGGER',
        'CREATE_VIEW' => 'CREATE_VIEW',
        'DELETE' => 'DELETE',
        'DROP_INDEX' => 'DROP_INDEX',
        'DROP_TABLE' => 'DROP_TABLE',
        'DROP_TEMP_INDEX' => 'DROP_TEMP_INDEX',
        'DROP_TEMP_TABLE' => 'DROP_TEMP_TABLE',
        'DROP_TEMP_TRIGGER' => 'DROP_TEMP_TRIGGER',
        'DROP_TEMP_VIEW' => 'DROP_TEMP_VIEW',
        'DROP_TRIGGER' => 'DROP_TRIGGER',
        'DROP_VIEW' => 'DROP_VIEW',
        'INSERT' => 'INSERT',
        'PRAGMA' => 'PRAGMA',
        'READ' => 'READ',
        'SELECT' => 'SELECT',
        'TRANSACTION' => 'TRANSACTION',
        'UPDATE' => 'UPDATE',
        'ATTACH' => 'ATTACH',
        'DETACH' => 'DETACH',
        'ALTER_TABLE' => 'ALTER_TABLE',
        'REINDEX' => 'REINDEX',
        'ANALYZE' => 'ANALYZE',
        'CREATE_VTABLE' => 'CREATE_VTABLE',
        'DROP_VTABLE' => 'DROP_VTABLE',
        'FUNCTION' => 'FUNCTION',
        'SAVEPOINT' => 'SAVEPOINT',
        'RECURSIVE' => 'RECURSIVE',
    ];

    /**
     * Legacy lowercase map keys; VmSQLite3Stmt registers under CLASS_CONSTANT_NAMES (#28098 / #25929).
     *
     * @var array<string, int>
     */
    public const STMT_CLASS_CONSTANTS = [
        'explain_mode_prepared' => self::EXPLAIN_MODE_PREPARED,
        'explain_mode_explain' => self::EXPLAIN_MODE_EXPLAIN,
        'explain_mode_explain_query_plan' => self::EXPLAIN_MODE_EXPLAIN_QUERY_PLAN,
    ];

    /** @var array<string, string> */
    public const STMT_CLASS_CONSTANT_NAMES = [
        'explain_mode_prepared' => 'EXPLAIN_MODE_PREPARED',
        'explain_mode_explain' => 'EXPLAIN_MODE_EXPLAIN',
        'explain_mode_explain_query_plan' => 'EXPLAIN_MODE_EXPLAIN_QUERY_PLAN',
    ];

    /**
     * Global SQLITE3_* constants (php-src ext/sqlite3/sqlite3.stub.php; #23732).
     *
     * @return array<string, int>
     */
    public static function globalConstants(): array
    {
        return [
            'SQLITE3_ASSOC' => self::ASSOC,
            'SQLITE3_NUM' => self::NUM,
            'SQLITE3_BOTH' => self::BOTH,
            'SQLITE3_INTEGER' => VmSqlite3Native::TYPE_INTEGER,
            'SQLITE3_FLOAT' => VmSqlite3Native::TYPE_FLOAT,
            'SQLITE3_TEXT' => VmSqlite3Native::TYPE_TEXT,
            'SQLITE3_BLOB' => VmSqlite3Native::TYPE_BLOB,
            'SQLITE3_NULL' => VmSqlite3Native::TYPE_NULL,
            'SQLITE3_OPEN_READONLY' => self::OPEN_READONLY,
            'SQLITE3_OPEN_READWRITE' => self::OPEN_READWRITE,
            'SQLITE3_OPEN_CREATE' => self::OPEN_CREATE,
        ];
    }
}
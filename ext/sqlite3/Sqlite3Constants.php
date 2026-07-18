<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

/**
 * SQLite3 class constants (php-src ext/sqlite3/sqlite3.stub.php; issue #3434).
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

    /** @var array<string, int> */
    public const CLASS_CONSTANTS = [
        'OPEN_READONLY' => self::OPEN_READONLY,
        'OPEN_READWRITE' => self::OPEN_READWRITE,
        'OPEN_CREATE' => self::OPEN_CREATE,
        'ASSOC' => self::ASSOC,
        'NUM' => self::NUM,
        'BOTH' => self::BOTH,
    ];

    /** @var array<string, string> */
    public const CLASS_CONSTANT_NAMES = [
        'OPEN_READONLY' => 'OPEN_READONLY',
        'OPEN_READWRITE' => 'OPEN_READWRITE',
        'OPEN_CREATE' => 'OPEN_CREATE',
        'ASSOC' => 'ASSOC',
        'NUM' => 'NUM',
        'BOTH' => 'BOTH',
    ];

    /** @var array<string, int> Storage keys lowercase (VM class-const lookup). */
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
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

/** Internal SQLite3 property names for JIT/AOT (#35914 leftover of #20565). */
final class Sqlite3JitSupport
{
    public const CLASS_NAME = 'SQLite3';

    public const PROP_ID = '__sqliteId';

    /** First-row first-column integer for querySingle scalar SELECT (#35914). */
    public const PROP_ROW = '__sqliteRow';

    /** Non-zero when PROP_ROW holds a querySingle scalar. */
    public const PROP_HAS = '__sqliteHas';

    /** sqlite3_last_insert_rowid fold after compile-time INSERT (#35931 leftover of #35914). */
    public const PROP_LAST_ROWID = '__sqliteRid';

    /** sqlite3_changes fold after last compile-time exec() (#35931 leftover of #35914). */
    public const PROP_CHANGES = '__sqliteChg';

    /** Folded row count for querySingle COUNT(*) (#35956 leftover of #35931). */
    public const PROP_ROW_COUNT = '__sqliteN';

    /** Running SUM of first-column ints for querySingle SUM(#col) (#35956). */
    public const PROP_SUM = '__sqliteSum';

    /** Non-zero when CREATE TABLE used INTEGER PRIMARY KEY (#35956). */
    public const PROP_INT_PK = '__sqlitePk';

    /** Non-zero when enableExceptions(true) is in effect (#35975 leftover of #35972). */
    public const PROP_EXCEPTIONS = '__sqliteEx';

    /** Compile-time fold session id (host libsqlite3 during AOT emit). */
    public const PROP_FOLD_ID = '__sqliteFoldId';

    /** SQLite3Stmt NestedJIT (#36010 leftover of #36001). */
    public const STMT_CLASS = 'SQLite3Stmt';

    public const STMT_PROP_SQL = '__sqliteStmtSql';

    public const STMT_PROP_PARAM_COUNT = '__sqliteStmtN';

    /** Fold stmt id for bindValue/execute (#36010 compliance). */
    public const STMT_PROP_FOLD_ID = '__sqliteStmtFoldId';

    /** SQLite3Result NestedJIT (#36010 leftover of #36001). */
    public const RESULT_CLASS = 'SQLite3Result';

    /** Copied from DB PROP_ROW for folded scalar SELECT fetchArray. */
    public const RESULT_PROP_ROW = '__sqliteResRow';

    public const RESULT_PROP_HAS = '__sqliteResHas';

    /** Row cursor for multi-row fetchArray (#36010 compliance). */
    public const RESULT_PROP_CURSOR = '__sqliteResCursor';

    /** Packed row count for multi-row result sets. */
    public const RESULT_PROP_ROW_COUNT = '__sqliteResN';
}

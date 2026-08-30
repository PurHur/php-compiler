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

    /** SQLite3Stmt::getSQL fold — compile-time SQL literal (#36010 leftover of #36001). */
    public const PROP_STMT_SQL = '__sqliteSql';

    /** SQLite3Result::fetchArray — first-column int when folded (#36010). */
    public const PROP_RESULT_VAL = '__sqliteResVal';

    /** Non-zero when PROP_RESULT_VAL holds a row (#36010). */
    public const PROP_RESULT_HAS = '__sqliteResHas';

    /** Non-zero after fetchArray consumed the row (#36010). */
    public const PROP_RESULT_FETCHED = '__sqliteResDone';

    /** First SELECT column name for ASSOC/BOTH modes (#36010). */
    public const PROP_RESULT_COL = '__sqliteResCol';

    public const CLASS_STMT = 'SQLite3Stmt';

    public const CLASS_RESULT = 'SQLite3Result';
}

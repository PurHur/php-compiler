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
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

/** Internal SQLite3 property names for JIT/AOT (#35914 leftover of #20565). */
final class Sqlite3JitSupport
{
    public const CLASS_NAME = 'SQLite3';

    public const PROP_ID = '__sqliteId';

    /** Last INSERT integer folded from compile-time exec() SQL. */
    public const PROP_ROW = '__sqliteRow';

    /** Non-zero when PROP_ROW holds a querySingle scalar. */
    public const PROP_HAS = '__sqliteHas';

    /** sqlite3_last_insert_rowid fold after compile-time INSERT (#35931 leftover of #35914). */
    public const PROP_LAST_ROWID = '__sqliteRid';

    /** sqlite3_changes fold after last compile-time exec() (#35931 leftover of #35914). */
    public const PROP_CHANGES = '__sqliteChg';
}

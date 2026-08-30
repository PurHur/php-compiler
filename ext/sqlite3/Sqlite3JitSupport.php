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
}

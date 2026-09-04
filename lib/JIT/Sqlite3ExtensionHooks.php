<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * sqlite3 extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/sqlite3/JitSqlite3ExtensionHooksFacade.php}; Call
 * Sqlite3* files must not import {@code ext\sqlite3}.
 */
interface Sqlite3ExtensionHooks
{
    /** SQLite3 instance / static method thin-AOT dispatch. */
    public function sqlite3Method(Context $context, string $method, Variable ...$args): Value;

    /** SQLite3Result method thin-AOT dispatch. */
    public function resultMethod(Context $context, string $method, Variable ...$args): Value;

    /** SQLite3Stmt method thin-AOT dispatch. */
    public function stmtMethod(Context $context, string $method, Variable ...$args): Value;
}

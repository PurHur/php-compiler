<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Sqlite3ExtensionHooks;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * sqlite3 surfaces for lib/JIT Call Sqlite3* (#36204).
 *
 * php-src: ext/sqlite3/sqlite3.c — SQLite3 / SQLite3Result / SQLite3Stmt thin-AOT.
 * Registered from {@see Module::jitInit} so Call files do not import ext/sqlite3.
 */
final class JitSqlite3ExtensionHooksFacade implements Sqlite3ExtensionHooks
{
    public function sqlite3Method(Context $context, string $method, JITVariable ...$args): Value
    {
        return match (strtolower($method)) {
            '__construct' => JitSqlite3::construct($context, ...$args),
            'exec' => JitSqlite3::exec($context, ...$args),
            'querysingle' => JitSqlite3::querySingle($context, ...$args),
            'close' => JitSqlite3::close($context, ...$args),
            'lastinsertrowid' => JitSqlite3::lastInsertRowID($context, ...$args),
            'changes' => JitSqlite3::changes($context, ...$args),
            'lasterrorcode' => JitSqlite3::lastErrorCode($context, ...$args),
            'lasterrormsg' => JitSqlite3::lastErrorMsg($context, ...$args),
            'busytimeout' => JitSqlite3::busyTimeout($context, ...$args),
            'enableexceptions' => JitSqlite3::enableExceptions($context, ...$args),
            'escapestring' => JitSqlite3::escapeString($context, ...$args),
            'version' => JitSqlite3::version($context, ...$args),
            'open' => JitSqlite3::open($context, ...$args),
            'prepare' => JitSqlite3::prepare($context, ...$args),
            'query' => JitSqlite3::query($context, ...$args),
            default => throw new \LogicException(
                'SQLite3::'.$method.'() JIT dispatch missing (#35931 / #36204)'
            ),
        };
    }

    public function resultMethod(Context $context, string $method, JITVariable ...$args): Value
    {
        return match (strtolower($method)) {
            'fetcharray' => JitSqlite3Result::fetchArray($context, ...$args),
            'columntype' => JitSqlite3Result::columnType($context, ...$args),
            default => throw new \LogicException(
                'SQLite3Result::'.$method.'() JIT dispatch missing (#36010 / #36204)'
            ),
        };
    }

    public function stmtMethod(Context $context, string $method, JITVariable ...$args): Value
    {
        return match (strtolower($method)) {
            'getsql' => JitSqlite3Stmt::getSQL($context, ...$args),
            'paramcount' => JitSqlite3Stmt::paramCount($context, ...$args),
            'bindvalue' => JitSqlite3Stmt::bindValue($context, ...$args),
            'bindparam' => JitSqlite3Stmt::bindParam($context, ...$args),
            'execute' => JitSqlite3Stmt::execute($context, ...$args),
            'readonly' => JitSqlite3Stmt::readOnly($context, ...$args),
            default => throw new \LogicException(
                'SQLite3Stmt::'.$method.'() JIT dispatch missing (#36010 / #36204)'
            ),
        };
    }
}

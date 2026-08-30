<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

/**
 * Compile-time SQLite3 session for thin AOT (#36010 compliance extension).
 * Uses host libsqlite3 FFI during compilation — same honesty class as
 * {@see JitSqlite3::escapeString} / {@see JitSqlite3::version}.
 *
 * php-src: ext/sqlite3/sqlite3.c
 */
final class Sqlite3AotFoldState
{
    /** @var array<int, \FFI\CData> */
    private static array $dbs = [];

    /** @var array<int, array{dbId: int, sql: string, binds: array<int, mixed>}> */
    private static array $stmts = [];

    private static int $nextDbId = 1;

    private static int $nextStmtId = 1;

    public static function newDb(): int
    {
        if (!VmSqlite3Native::available()) {
            return 0;
        }
        $id = self::$nextDbId++;
        self::$dbs[$id] = VmSqlite3Native::open(':memory:', 6);

        return $id;
    }

    public static function exec(int $dbId, string $sql): void
    {
        if ($dbId <= 0 || !isset(self::$dbs[$dbId])) {
            return;
        }
        VmSqlite3Native::exec(self::$dbs[$dbId], $sql);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function queryRows(int $dbId, string $sql): array
    {
        if ($dbId <= 0 || !isset(self::$dbs[$dbId])) {
            return [];
        }
        $db = self::$dbs[$dbId];
        $stmt = VmSqlite3Native::prepare($db, $sql);
        $rows = [];
        while (true) {
            $rc = VmSqlite3Native::step($stmt);
            if (VmSqlite3Native::STEP_ROW !== $rc) {
                break;
            }
            $count = VmSqlite3Native::columnCount($stmt);
            $assoc = [];
            $num = [];
            for ($i = 0; $i < $count; ++$i) {
                $name = VmSqlite3Native::columnName($stmt, $i);
                $value = VmSqlite3Native::columnValueAt($stmt, $i);
                $assoc[$name] = $value;
                $num[$i] = $value;
            }
            $rows[] = ['assoc' => $assoc, 'num' => $num];
        }
        VmSqlite3Native::finalize($stmt);

        return $rows;
    }

    public static function prepare(int $dbId, string $sql): int
    {
        $id = self::$nextStmtId++;
        self::$stmts[$id] = ['dbId' => $dbId, 'sql' => $sql, 'binds' => []];

        return $id;
    }

    public static function bindValue(int $stmtId, int $param, mixed $value): void
    {
        if (!isset(self::$stmts[$stmtId])) {
            return;
        }
        self::$stmts[$stmtId]['binds'][$param] = $value;
    }

    public static function stmtExecute(int $stmtId): bool
    {
        if (!isset(self::$stmts[$stmtId])) {
            return false;
        }
        $st = self::$stmts[$stmtId];
        if ($st['dbId'] <= 0 || !isset(self::$dbs[$st['dbId']])) {
            return false;
        }
        $db = self::$dbs[$st['dbId']];
        $stmt = VmSqlite3Native::prepare($db, $st['sql']);
        foreach ($st['binds'] as $idx => $value) {
            VmSqlite3Native::bindValue($stmt, (int) $idx, $value);
        }
        $rc = VmSqlite3Native::step($stmt);
        VmSqlite3Native::reset($stmt);
        VmSqlite3Native::finalize($stmt);

        return VmSqlite3Native::STEP_DONE === $rc || VmSqlite3Native::STEP_ROW === $rc;
    }

    public static function changes(int $dbId): int
    {
        if ($dbId <= 0 || !isset(self::$dbs[$dbId])) {
            return 0;
        }

        return VmSqlite3Native::changes(self::$dbs[$dbId]);
    }

    public static function lastInsertRowId(int $dbId): int
    {
        if ($dbId <= 0 || !isset(self::$dbs[$dbId])) {
            return 0;
        }

        return (int) VmSqlite3Native::lastInsertRowId(self::$dbs[$dbId]);
    }

    public static function paramCount(string $sql): int
    {
        return substr_count($sql, '?') + substr_count($sql, ':');
    }
}

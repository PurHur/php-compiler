<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::busyTimeout(int $milliseconds): bool — php-src zim_SQLite3_busyTimeout (#19862). */
final class SQLite3BusyTimeout extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('busyTimeout');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::busyTimeout()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SQLite3::busyTimeout() expects exactly 1 argument, 0 given');
        }
        $ms = $this->intArg($frame->calledArgs[1], 'SQLite3::busyTimeout', 0, 'milliseconds');
        $db = VmSQLite3::requireOpenDb($receiver, 'SQLite3::busyTimeout');
        $ok = VmSqlite3Native::busyTimeout($db, $ms);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

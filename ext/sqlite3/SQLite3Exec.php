<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::exec(string $query) — VM (#3434, #19862 exception mode). */
final class SQLite3Exec extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('exec');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::exec()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SQLite3::exec() expects at least 1 argument, 0 given');
        }
        try {
            $query = VmSQLite3::expandSql(
                $receiver,
                $this->stringArg($frame->calledArgs[1], 'SQLite3::exec', 0, 'query')
            );
            $db = VmSQLite3::requireOpenDb($receiver, 'SQLite3::exec');
            $ok = VmSqlite3Native::exec($db, $query);
        } catch (\SQLite3Exception $e) {
            if (VmSQLite3::handleException($receiver, $e)) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            $ok = false;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

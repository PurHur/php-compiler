<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::querySingle(string $query, bool $entireRow = false) — VM (#3434, #19862). */
final class SQLite3QuerySingle extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('querySingle');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::querySingle()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SQLite3::querySingle() expects at least 1 argument, 0 given');
        }
        $query = VmSQLite3::expandSql(
            $receiver,
            $this->stringArg($frame->calledArgs[1], 'SQLite3::querySingle', 0, 'query')
        );
        $entireRow = \count($frame->calledArgs) >= 3
            ? $this->boolArg($frame->calledArgs[2], 'SQLite3::querySingle', 1, 'entireRow')
            : false;
        $db = VmSQLite3::requireOpenDb($receiver, 'SQLite3::querySingle');
        try {
            $value = VmSqlite3Native::querySingle($db, $query, $entireRow);
        } catch (\SQLite3Exception $e) {
            if (VmSQLite3::handleException($receiver, $e)) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            $value = false;
        }
        if (null !== $frame->returnVar) {
            VmSQLite3::assignReturnValue($frame->returnVar, $value);
        }
    }
}

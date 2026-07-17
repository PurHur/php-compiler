<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::prepare(string $query): SQLite3Stmt|false — VM (#19821). */
final class SQLite3Prepare extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('prepare');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::prepare()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SQLite3::prepare() expects exactly 1 argument, 0 given');
        }
        $query = VmSQLite3::expandSql(
            $receiver,
            $this->stringArg($frame->calledArgs[1], 'SQLite3::prepare', 0, 'query')
        );
        if ('' === $query) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $db = VmSQLite3::requireOpenDb($receiver, 'SQLite3::prepare');
        try {
            $stmt = VmSqlite3Native::prepare($db, $query);
            $object = VmSQLite3Stmt::create($receiver, $stmt, $query);
            if (null !== $frame->returnVar) {
                $frame->returnVar->object($object);
            }
        } catch (\SQLite3Exception $e) {
            if (VmSQLite3::handleException($receiver, $e)) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::lastInsertRowID(): int — VM (#19821). */
final class SQLite3LastInsertRowID extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('lastInsertRowID');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::lastInsertRowID()');
        $db = VmSQLite3::requireOpenDb($receiver, 'SQLite3::lastInsertRowID');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmSqlite3Native::lastInsertRowId($db));
        }
    }
}

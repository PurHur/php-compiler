<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::changes(): int — VM (#19821). */
final class SQLite3Changes extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('changes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::changes()');
        $db = VmSQLite3::requireOpenDb($receiver, 'SQLite3::changes');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmSqlite3Native::changes($db));
        }
    }
}

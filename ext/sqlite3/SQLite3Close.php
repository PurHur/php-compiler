<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::close() — VM (#3434). */
final class SQLite3Close extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::close()');
        $state = VmSQLite3::state($receiver);
        if ($state->closed || null === $state->db) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ok = VmSqlite3Native::close($state->db);
        $state->db = null;
        $state->closed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

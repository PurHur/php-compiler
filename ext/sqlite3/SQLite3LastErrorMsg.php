<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::lastErrorMsg(): string — php-src ext/sqlite3/sqlite3.c; #20565. */
final class SQLite3LastErrorMsg extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('lastErrorMsg');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::lastErrorMsg()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmSQLite3::lastErrorMsg($receiver));
        }
    }
}

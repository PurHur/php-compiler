<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::lastErrorCode(): int — php-src ext/sqlite3/sqlite3.c; #20565. */
final class SQLite3LastErrorCode extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('lastErrorCode');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::lastErrorCode()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmSQLite3::lastErrorCode($receiver));
        }
    }
}

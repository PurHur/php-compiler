<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::enableExceptions(bool $enable = true): bool — prior mode (#19862). */
final class SQLite3EnableExceptions extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('enableExceptions');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::enableExceptions()');
        $enable = true;
        if (\count($frame->calledArgs) >= 2) {
            $enable = $this->boolArg($frame->calledArgs[1], 'SQLite3::enableExceptions', 0, 'enable');
        }
        $state = VmSQLite3::state($receiver);
        $prior = $state->exceptions;
        $state->exceptions = $enable;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($prior);
        }
    }
}

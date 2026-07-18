<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/**
 * SQLite3::open(string $filename, int $flags = …, string $encryptionKey = ""): void
 * (php-src ext/sqlite3/sqlite3.c; #20565).
 */
final class SQLite3Open extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('open');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SQLite3::open() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('SQLite3::open() must be called on SQLite3');
        }
        $receiver = $var->toObject();
        if (VmSQLite3::CLASS_LC !== strtolower($receiver->class->name)) {
            throw new \TypeError('SQLite3::open() must be called on SQLite3');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SQLite3::open() expects at least 1 argument, 0 given');
        }
        $filename = $this->stringArg($frame->calledArgs[1], 'SQLite3::open', 0, 'filename');
        $defaultFlags = Sqlite3Constants::OPEN_READWRITE | Sqlite3Constants::OPEN_CREATE;
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'SQLite3::open', 1, 'flags', $defaultFlags)
            : $defaultFlags;
        VmSQLite3::openObject($receiver, $filename, $flags);
    }
}

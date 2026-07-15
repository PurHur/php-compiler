<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::__construct(string $filename, int $flags = OPEN_READWRITE|OPEN_CREATE, ?string $encryption_key = null) — VM (#3434). */
final class SQLite3Construct extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SQLite3::__construct() expects at least 1 argument, 0 given');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SQLite3::__construct() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('SQLite3::__construct() must be called on SQLite3');
        }
        $receiver = $var->toObject();
        if (VmSQLite3::CLASS_LC !== strtolower($receiver->class->name)) {
            throw new \TypeError('SQLite3::__construct() must be called on SQLite3');
        }
        if ($receiver->constructed) {
            throw new \LogicException('SQLite3 object already initialized');
        }
        $filename = $this->stringArg($frame->calledArgs[1], 'SQLite3::__construct', 0, 'filename');
        $defaultFlags = Sqlite3Constants::OPEN_READWRITE | Sqlite3Constants::OPEN_CREATE;
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'SQLite3::__construct', 1, 'flags', $defaultFlags)
            : $defaultFlags;
        VmSQLite3::initObject($receiver, $filename, $flags);
    }
}

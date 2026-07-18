<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * SQLite3::backup(SQLite3 $destination, string $sourceDB = "main", string $destinationDB = "main"): bool
 * (php-src ext/sqlite3/sqlite3.c; #20565).
 */
final class SQLite3Backup extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('backup');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::backup()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SQLite3::backup() expects at least 1 argument, 0 given');
        }
        $destVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $destVar->type) {
            throw new \TypeError(\sprintf(
                'SQLite3::backup(): Argument #1 ($destination) must be of type SQLite3, %s given',
                VmSQLite3::publicTypeLabel($destVar)
            ));
        }
        $dest = $destVar->toObject();
        if (VmSQLite3::CLASS_LC !== strtolower($dest->class->name)) {
            throw new \TypeError(\sprintf(
                'SQLite3::backup(): Argument #1 ($destination) must be of type SQLite3, %s given',
                $dest->class->name
            ));
        }
        if (!$dest->constructed) {
            throw new \TypeError('SQLite3::backup(): Argument #1 ($destination) must be of type SQLite3, uninitialized SQLite3 given');
        }
        $sourceDb = 'main';
        $destDb = 'main';
        if (\count($frame->calledArgs) >= 3) {
            $sourceDb = $this->stringArg($frame->calledArgs[2], 'SQLite3::backup', 1, 'sourceDatabase');
        }
        if (\count($frame->calledArgs) >= 4) {
            $destDb = $this->stringArg($frame->calledArgs[3], 'SQLite3::backup', 2, 'destinationDatabase');
        }
        $srcHandle = VmSQLite3::requireOpenDb($receiver, 'SQLite3::backup');
        $dstHandle = VmSQLite3::requireOpenDb($dest, 'SQLite3::backup');
        try {
            $ok = VmSqlite3Native::backup($srcHandle, $sourceDb, $dstHandle, $destDb);
        } catch (\SQLite3Exception $e) {
            if (VmSQLite3::handleException($receiver, $e)) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            $ok = false;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

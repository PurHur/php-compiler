<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/**
 * SQLite3::openBlob(string $table, string $column, int $rowid, string $database = "main", int $flags = SQLITE3_OPEN_READONLY): resource|false
 * (php-src ext/sqlite3/sqlite3.c; #20599).
 */
final class SQLite3OpenBlob extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('openBlob');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::openBlob()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'SQLite3::openBlob() expects at least 3 arguments, '.$argc.' given'
            );
        }
        $table = $this->stringArg($frame->calledArgs[1], 'SQLite3::openBlob', 0, 'table');
        $column = $this->stringArg($frame->calledArgs[2], 'SQLite3::openBlob', 1, 'column');
        $rowid = $this->intArg($frame->calledArgs[3], 'SQLite3::openBlob', 2, 'rowid');
        $database = 'main';
        $flags = Sqlite3Constants::OPEN_READONLY;
        if (\count($frame->calledArgs) >= 5) {
            $database = $this->stringArg($frame->calledArgs[4], 'SQLite3::openBlob', 3, 'database');
        }
        if (\count($frame->calledArgs) >= 6) {
            $flags = $this->intArg($frame->calledArgs[5], 'SQLite3::openBlob', 4, 'flags', Sqlite3Constants::OPEN_READONLY);
        }
        $db = VmSQLite3::requireOpenDb($receiver, 'SQLite3::openBlob');
        try {
            $blob = VmSqlite3Native::blobOpen($db, $database, $table, $column, $rowid, $flags);
            $size = VmSqlite3Native::blobBytes($blob);
            $handle = VmSqlite3BlobStream::open($blob, $flags, $size);
            if (null !== $frame->returnVar) {
                $frame->returnVar->streamHandle($handle, $frame->vmContext);
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

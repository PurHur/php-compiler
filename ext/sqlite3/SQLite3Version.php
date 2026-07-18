<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * SQLite3::version(): array{versionString: string, versionNumber: int} — static
 * (php-src ext/sqlite3/sqlite3.c; #20565).
 */
final class SQLite3Version extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('version');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $info = VmSqlite3Native::version();
        $ht = new HashTable();
        $str = new Variable();
        $str->string($info['versionString']);
        $ht->add('versionString', $str);
        $num = new Variable();
        $num->int($info['versionNumber']);
        $ht->add('versionNumber', $num);
        $frame->returnVar->array($ht);
    }
}

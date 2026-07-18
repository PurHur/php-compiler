<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * SQLite3::loadExtension(string $name): bool
 * (php-src ext/sqlite3/sqlite3.c; #20585).
 *
 * Honors sqlite3.extension_dir; returns false when disabled or load fails (host capability).
 */
final class SQLite3LoadExtension extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('loadExtension');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::loadExtension()');
        $db = VmSQLite3::requireOpenDb($receiver, 'SQLite3::loadExtension');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SQLite3::loadExtension() expects exactly 1 argument, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $nameVar = $frame->calledArgs[1]->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($nameVar)) {
            throw new \TypeError(\sprintf(
                'SQLite3::loadExtension(): Argument #1 ($name) must be of type string, %s given',
                EnumCaseSupport::typeNameForVariable($nameVar)
            ));
        }
        if (Variable::TYPE_NULL === $nameVar->type) {
            throw new \TypeError('SQLite3::loadExtension(): Argument #1 ($name) must be of type string, null given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'SQLite3::loadExtension', 1, 'name');
        if ('' === $name) {
            throw new \ValueError('SQLite3::loadExtension(): Argument #1 ($name) must not be empty');
        }
        $ok = VmSqlite3Native::loadExtension($db, $name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

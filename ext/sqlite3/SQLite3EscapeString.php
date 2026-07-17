<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;

/** SQLite3::escapeString(string $string): string — static; VM (#19821). */
final class SQLite3EscapeString extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('escapeString');
    }

    public function execute(Frame $frame): void
    {
        // Static: args[0] may be unused receiver or first real arg when called statically.
        $offset = 0;
        if (\count($frame->calledArgs) >= 1) {
            $first = $frame->calledArgs[0]->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_OBJECT === $first->type
                && 'sqlite3' === strtolower($first->toObject()->class->name)) {
                $offset = 1;
            }
        }
        if (\count($frame->calledArgs) < $offset + 1) {
            throw new \ArgumentCountError('SQLite3::escapeString() expects exactly 1 argument, 0 given');
        }
        $value = $this->stringArg($frame->calledArgs[$offset], 'SQLite3::escapeString', 0, 'string');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmSqlite3Native::escapeString($value));
        }
    }
}

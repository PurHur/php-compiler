<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\sqlite3\JitSqlite3Stmt;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** SQLite3Stmt NestedJIT (#36010 leftover of #36001). php-src: ext/sqlite3/sqlite3.c */
final class Sqlite3StmtMethod implements Call
{
    public string $name;

    /** @var list<string> */
    public array $paramNames = [];

    public int $namedArgsReceiverPrefix = 1;

    public function __construct(
        private readonly string $method,
    ) {
        $this->name = 'SQLite3Stmt::'.$method;
        $lc = strtolower($method);
        if ('getsql' === $lc) {
            $this->paramNames = ['expand='];
        } elseif ('paramcount' === $lc) {
            $this->paramNames = [];
        }
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match (strtolower($this->method)) {
            'getsql' => JitSqlite3Stmt::getSQL($context, ...$args),
            'paramcount' => JitSqlite3Stmt::paramCount($context, ...$args),
            default => throw new \LogicException(
                'SQLite3Stmt::'.$this->method.'() JIT dispatch missing (#36010)'
            ),
        };
    }
}

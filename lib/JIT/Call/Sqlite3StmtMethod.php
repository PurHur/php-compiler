<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * SQLite3Stmt NestedJIT (#36010 leftover of #36001).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\sqlite3} (#36204). php-src: ext/sqlite3/sqlite3.c
 */
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
        } elseif ('bindvalue' === $lc) {
            $this->paramNames = ['param', 'value'];
        } elseif ('bindparam' === $lc) {
            $this->paramNames = ['param', 'var'];
        } elseif ('execute' === $lc) {
            $this->paramNames = [];
        } elseif ('readonly' === $lc) {
            $this->paramNames = [];
        }
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireSqlite3()->stmtMethod(
            $context,
            $this->method,
            ...$args
        );
    }
}

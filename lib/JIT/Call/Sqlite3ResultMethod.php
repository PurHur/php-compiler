<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\sqlite3\JitSqlite3Result;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * SQLite3Result thin-AOT methods — fetchArray (#36010 leftover of #36001).
 *
 * php-src: ext/sqlite3/sqlite3.c zim_sqlite3_result_fetcharray
 */
final class Sqlite3ResultMethod implements Call
{
    public string $name;

    /** @var list<string> */
    public array $paramNames = [];

    public int $namedArgsReceiverPrefix = 1;

    public function __construct(
        private readonly string $method,
    ) {
        $this->name = 'SQLite3Result::'.$method;
        if ('fetcharray' === strtolower($method)) {
            $this->paramNames = ['mode='];
        }
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match (strtolower($this->method)) {
            'fetcharray' => JitSqlite3Result::fetchArray($context, ...$args),
            default => throw new \LogicException(
                'SQLite3Result::'.$this->method.'() JIT dispatch missing (#36010)'
            ),
        };
    }
}

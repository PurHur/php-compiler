<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\sqlite3\JitSqlite3;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * SQLite3 thin-AOT methods — __construct / exec / querySingle / close
 * (#35914 leftover of #20565 / #19821).
 *
 * php-src: ext/sqlite3/sqlite3.c
 */
final class Sqlite3Method implements Call
{
    public string $name;

    /** @var list<string> */
    public array $paramNames = [];

    public int $namedArgsReceiverPrefix = 1;

    public function __construct(
        private readonly string $method,
    ) {
        $this->name = 'SQLite3::'.$method;
        $lc = strtolower($method);
        if ('__construct' === $lc) {
            $this->paramNames = ['filename', 'flags=', 'encryption_key='];
        } elseif ('exec' === $lc) {
            $this->paramNames = ['query'];
        } elseif ('querysingle' === $lc) {
            $this->paramNames = ['query', 'entireRow='];
        }
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match (strtolower($this->method)) {
            '__construct' => JitSqlite3::construct($context, ...$args),
            'exec' => JitSqlite3::exec($context, ...$args),
            'querysingle' => JitSqlite3::querySingle($context, ...$args),
            'close' => JitSqlite3::close($context, ...$args),
            default => throw new \LogicException(
                'SQLite3::'.$this->method.'() JIT dispatch missing (#35914)'
            ),
        };
    }
}

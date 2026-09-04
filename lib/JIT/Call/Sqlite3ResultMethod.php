<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * SQLite3Result NestedJIT (#36010 leftover of #36001).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\sqlite3} (#36204). php-src: ext/sqlite3/sqlite3.c
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
        } elseif ('columntype' === strtolower($method)) {
            $this->paramNames = ['column'];
        }
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireSqlite3()->resultMethod(
            $context,
            $this->method,
            ...$args
        );
    }
}

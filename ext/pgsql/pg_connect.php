<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * pg_connect() — libpq PQconnectdb (php-src ext/pgsql/pgsql.c; #3741).
 */
final class pg_connect extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_connect() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conninfo = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_connect', 0, 'connection_string');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_connect() requires a VM context');
        }
        $result = VmPgsqlCore::connect($conninfo, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_connect() is not implemented for JIT in this compiler build (issue #3741)');
    }
}

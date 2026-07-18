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
 * pg_query() — PQexec (php-src ext/pgsql/pgsql.c; #3741).
 */
final class pg_query extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_query');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_query() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_query', 1);
        $query = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_query', 1, 'query');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_query() requires a VM context');
        }
        $result = VmPgsqlCore::query($conn, $query, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_query() is not implemented for JIT in this compiler build (issue #3741)');
    }
}

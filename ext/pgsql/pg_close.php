<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pg_close() — PQfinish (php-src ext/pgsql/pgsql.c; #3741).
 */
final class pg_close extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_close() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_close', 1);
        $ok = VmPgsqlConnection::close($conn);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_close() is not implemented for JIT in this compiler build (issue #3741)');
    }
}

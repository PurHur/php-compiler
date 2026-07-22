<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pg_num_rows() — PQntuples (php-src ext/pgsql/pgsql.c; #3741).
 */
final class pg_num_rows extends Internal
{
    public function __construct(string $name = 'pg_num_rows')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_num_rows() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_num_rows', 1);
        $frame->returnVar->int(VmPgsqlNative::ntuples(VmPgsqlResult::native($result)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_num_rows() is not implemented for JIT in this compiler build (issue #3741)');
    }
}

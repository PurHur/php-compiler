<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pg_result_memory_size() — PQresultMemorySize (php-src ext/pgsql; #7083).
 */
final class pg_result_memory_size extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_result_memory_size');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_result_memory_size() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_result_memory_size', 1);
        $frame->returnVar->int(VmPgsqlNative::resultMemorySize(VmPgsqlResult::native($result)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_result_memory_size() is not implemented for JIT (#7083)');
    }
}

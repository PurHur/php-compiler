<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pg_fetch_assoc() — next row as associative array (php-src ext/pgsql/pgsql.c; #3741).
 */
final class pg_fetch_assoc extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_fetch_assoc');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_fetch_assoc() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_fetch_assoc', 1);
        $row = VmPgsqlCore::fetchAssoc($result);
        if (false === $row) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($row);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_fetch_assoc() is not implemented for JIT in this compiler build (issue #3741)');
    }
}

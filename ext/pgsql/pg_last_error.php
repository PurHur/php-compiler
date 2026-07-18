<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pg_last_error() — last connection / connect error (php-src ext/pgsql/pgsql.c; #3741).
 */
final class pg_last_error extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_last_error');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'pg_last_error() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (1 === $argc) {
            $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_last_error', 1);
            $msg = VmPgsqlNative::errorMessage(VmPgsqlConnection::native($conn));
            $frame->returnVar->string($msg);

            return;
        }
        $frame->returnVar->string(VmPgsqlConnection::lastError());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_last_error() is not implemented for JIT in this compiler build (issue #3741)');
    }
}

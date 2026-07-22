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
    public function __construct(string $name = 'pg_last_error')
    {
        parent::__construct($name);
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

/**
 * pg_last_notice() — connection NOTICE buffer (php-src ext/pgsql/pgsql.c; #22217).
 */
final class pg_last_notice extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_last_notice');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_last_notice() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_last_notice', 1);
        $mode = PgsqlConstants::PGSQL_NOTICE_LAST;
        if ($argc >= 2) {
            $mode = $frame->calledArgs[1]->resolveIndirect()->toInt();
        }
        $out = VmPgsqlConnection::lastNotice($conn, $mode);
        if (\is_bool($out)) {
            $frame->returnVar->bool($out);

            return;
        }
        if (\is_string($out)) {
            $frame->returnVar->string($out);

            return;
        }
        $frame->returnVar->array($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_last_notice() is not implemented for JIT (#22217)');
    }
}

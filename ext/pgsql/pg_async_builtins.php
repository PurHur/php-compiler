<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pg_socket / pg_consume_input / pg_flush (php-src ext/pgsql; #20636).
 * Loaded via Module::getFunctions() + spine require.
 */

final class pg_socket extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_socket');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_socket() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_socket', 1);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_socket() requires a VM context');
        }
        $sock = VmPgsqlCore::socket($conn, $ctx);
        if (false === $sock) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($sock);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_socket() is not implemented for JIT (#20636)');
    }
}

final class pg_consume_input extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_consume_input');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_consume_input() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_consume_input', 1);
        $frame->returnVar->bool(VmPgsqlCore::consumeInput($conn));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_consume_input() is not implemented for JIT (#20636)');
    }
}

final class pg_flush extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_flush');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_flush() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_flush', 1);
        $out = VmPgsqlCore::flush($conn);
        if (true === $out) {
            $frame->returnVar->bool(true);

            return;
        }
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_flush() is not implemented for JIT (#20636)');
    }
}

/**
 * pg_set_error_verbosity (php-src ext/pgsql/pgsql.c; #20660).
 */
final class pg_set_error_verbosity extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_set_error_verbosity');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_set_error_verbosity() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_set_error_verbosity', 1);
        $verbosityVar = $frame->calledArgs[1]->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER !== $verbosityVar->type
            && \PHPCompiler\VM\Variable::TYPE_FLOAT !== $verbosityVar->type
            && \PHPCompiler\VM\Variable::TYPE_BOOLEAN !== $verbosityVar->type
            && \PHPCompiler\VM\Variable::TYPE_STRING !== $verbosityVar->type
        ) {
            throw new \TypeError(\sprintf(
                'pg_set_error_verbosity(): Argument #2 ($verbosity) must be of type int, %s given',
                match ($verbosityVar->type) {
                    \PHPCompiler\VM\Variable::TYPE_NULL => 'null',
                    \PHPCompiler\VM\Variable::TYPE_ARRAY => 'array',
                    \PHPCompiler\VM\Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }
            ));
        }
        $verbosity = (int) $verbosityVar->toInt();
        $prev = VmPgsqlNative::setErrorVerbosity(VmPgsqlConnection::native($conn), $verbosity);
        $frame->returnVar->int($prev);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_set_error_verbosity() is not implemented for JIT (#20660)');
    }
}

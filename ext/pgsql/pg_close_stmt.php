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
 * pg_close_stmt() — PQclosePrepared when available (php-src HAVE_PG_CLOSE_STMT; #26191).
 *
 * Without PQclosePrepared (libpq &lt; 17) returns false — the close protocol cannot be emulated.
 */
final class pg_close_stmt extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_close_stmt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_close_stmt() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $connObj = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_close_stmt', 1);
        $stmt = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_close_stmt', 2, 'statement_name');
        if ('' === $stmt) {
            throw new \ValueError('pg_close_stmt(): Argument #2 ($statement_name) must not be empty');
        }
        if (!VmPgsqlNative::hasClosePrepared()) {
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_close_stmt() requires a VM context');
        }
        $nativeResult = VmPgsqlNative::closePrepared(VmPgsqlConnection::native($connObj), $stmt);
        if (null === $nativeResult) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage(VmPgsqlConnection::native($connObj)));
            $frame->returnVar->bool(false);

            return;
        }
        if (VmPgsqlNative::PGRES_COMMAND_OK !== VmPgsqlNative::resultStatus($nativeResult)) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage(VmPgsqlConnection::native($connObj)));
            VmPgsqlNative::clear($nativeResult);
            $frame->returnVar->bool(false);

            return;
        }
        $wrapped = VmPgsqlResult::wrap($nativeResult, $ctx, $connObj);
        $frame->returnVar->object($wrapped->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_close_stmt() is not implemented for JIT (#26191)');
    }
}

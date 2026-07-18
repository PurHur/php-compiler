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
 * pg_change_password() — ALTER ROLE via libpq (php-src uses PQchangePassword; #7083).
 */
final class pg_change_password extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_change_password');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_change_password() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $connObj = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_change_password', 1);
        $user = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_change_password', 1, 'user');
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'pg_change_password', 2, 'password');
        if ('' === $user) {
            throw new \ValueError('pg_change_password(): Argument #2 ($user) must not be empty');
        }
        if ('' === $password) {
            throw new \ValueError('pg_change_password(): Argument #3 ($password) must not be empty');
        }
        $conn = VmPgsqlConnection::native($connObj);
        $ident = VmPgsqlNative::escapeIdentifier($conn, $user);
        $lit = VmPgsqlNative::escapeLiteral($conn, $password);
        if ('' === $ident || '' === $lit) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
            $frame->returnVar->bool(false);

            return;
        }
        $sql = 'ALTER ROLE '.$ident.' PASSWORD '.$lit;
        $result = VmPgsqlNative::exec($conn, $sql);
        if (null === $result) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
            $frame->returnVar->bool(false);

            return;
        }
        $ok = VmPgsqlNative::PGRES_COMMAND_OK === VmPgsqlNative::resultStatus($result);
        if (!$ok) {
            VmPgsqlConnection::setLastError(VmPgsqlNative::errorMessage($conn));
        }
        VmPgsqlNative::clear($result);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_change_password() is not implemented for JIT (#7083)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * pg_socket_poll() — poll a socket (php-src uses PQsocketPoll; #7083).
 *
 * libpq 14 (Ubuntu 22.04) lacks PQsocketPoll; validate operands then return 0
 * (no readiness events), matching an idle poll when the native helper is absent.
 */
final class pg_socket_poll extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_socket_poll');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'pg_socket_poll() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sockVar = $frame->calledArgs[0]->resolveIndirect();
        VmMath::parseIntBuiltinArgForFrame($frame, 1, 'pg_socket_poll', 2, 'read');
        VmMath::parseIntBuiltinArgForFrame($frame, 2, 'pg_socket_poll', 3, 'write');
        if (4 === $argc) {
            VmMath::parseIntBuiltinArgForFrame($frame, 3, 'pg_socket_poll', 4, 'timeout');
        }

        $ok = Variable::TYPE_RESOURCE === $sockVar->type
            || Variable::TYPE_INTEGER === $sockVar->type;
        if (!$ok && Variable::TYPE_OBJECT === $sockVar->type) {
            $obj = $sockVar->toObject();
            $ok = VmPgsqlConnection::CLASS_LC === strtolower($obj->class->name)
                && VmPgsqlConnection::isLive($obj);
        }
        if (!$ok) {
            throw new \TypeError('pg_socket_poll(): Argument #1 ($socket) must be of type resource');
        }

        $frame->returnVar->int(0);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_socket_poll() is not implemented for JIT (#7083)');
    }
}

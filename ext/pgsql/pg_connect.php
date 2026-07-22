<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * pg_connect() — libpq PQconnectdb / PQconnectStart (php-src ext/pgsql/pgsql.c; #3741, #21896).
 */
final class pg_connect extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_connect() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conninfo = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_connect', 0, 'connection_string');
        $flags = 0;
        if (2 === $argc) {
            $flags = (int) VmMath::parseIntBuiltinArgForFrame($frame, 1, 'pg_connect', 2, 'flags');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_connect() requires a VM context');
        }
        $result = VmPgsqlCore::connect($conninfo, $ctx, $flags);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_connect() is not implemented for JIT in this compiler build (issue #3741)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pg_service() — PQservice when available (php-src HAVE_PG_SERVICE; #26191).
 *
 * Without PQservice (libpq &lt; 18) returns "" — same as a null PQservice result in php-src.
 */
final class pg_service extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_service');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'pg_service() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $provided = null;
        if (1 === $argc) {
            $provided = VmPgsqlArg::optionalConnection($frame->calledArgs[0], 'pg_service', 1);
        }
        $connObj = VmPgsqlConnection::requireOptionalOrDefault($provided, 'pg_service');
        if (null === $connObj) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string(VmPgsqlNative::service(VmPgsqlConnection::native($connObj)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_service() is not implemented for JIT (#26191)');
    }
}

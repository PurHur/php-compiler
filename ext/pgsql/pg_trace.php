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
 * pg_trace() — PQtrace (php-src ext/pgsql/pgsql.c; #20574).
 */
final class pg_trace extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_trace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'pg_trace() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pg_trace', 0, 'filename');
        $mode = 'w';
        if ($argc >= 2) {
            $mode = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_trace', 1, 'mode');
        }
        $provided = null;
        if ($argc >= 3) {
            $provided = VmPgsqlArg::optionalConnection($frame->calledArgs[2], 'pg_trace', 3);
        }
        // Omitted connection → FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31221).
        $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated($provided, $frame, 'pg_trace');
        $fp = VmPgsqlNative::trace(VmPgsqlConnection::native($connObj), $pathname, $mode);
        if (null === $fp) {
            @\trigger_error(
                \sprintf('pg_trace(): Unable to open "%s" for tracing', $pathname),
                \E_USER_WARNING
            );
            $frame->returnVar->bool(false);

            return;
        }
        VmPgsqlConnection::setTraceFp($connObj, $fp);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_trace() is not implemented for JIT in this compiler build (issue #20574)');
    }
}

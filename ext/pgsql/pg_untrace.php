<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pg_untrace() — PQuntrace (php-src ext/pgsql/pgsql.c; #20574).
 */
final class pg_untrace extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_untrace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'pg_untrace() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $provided = null;
        if (1 === $argc) {
            $provided = VmPgsqlArg::optionalConnection($frame->calledArgs[0], 'pg_untrace', 1);
        }
        // FETCH_DEFAULT_LINK + CHECK_DEFAULT_LINK (#31221).
        $connObj = VmPgsqlConnection::connectionOrDefaultDeprecated($provided, $frame, 'pg_untrace');
        VmPgsqlNative::untrace(VmPgsqlConnection::native($connObj));
        VmPgsqlConnection::clearTraceFp($connObj);
        // php-src stub: pg_untrace(): true
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_untrace() is not implemented for JIT in this compiler build (issue #20574)');
    }
}

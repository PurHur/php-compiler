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
 * pg_put_copy_data() — PQputCopyData (php-src ext/pgsql; #7083).
 */
final class pg_put_copy_data extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_put_copy_data');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_put_copy_data() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_put_copy_data', 1);
        $cmd = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_put_copy_data', 1, 'cmd');
        $frame->returnVar->int(VmPgsqlNative::putCopyData(VmPgsqlConnection::native($conn), $cmd));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_put_copy_data() is not implemented for JIT (#7083)');
    }
}

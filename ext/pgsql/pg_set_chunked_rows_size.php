<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * pg_set_chunked_rows_size() — PQsetChunkedRowsMode when available (#7083).
 *
 * libpq without chunked-rows support returns false (php-src HAVE_PG_SET_CHUNKED_ROWS_SIZE).
 */
final class pg_set_chunked_rows_size extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_set_chunked_rows_size');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_set_chunked_rows_size() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_set_chunked_rows_size', 1);
        $size = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'pg_set_chunked_rows_size', 2, 'size');
        if ($size < 1 || $size > 2147483647) {
            throw new \ValueError(\sprintf(
                'pg_set_chunked_rows_size(): Argument #2 ($size) must be between 1 and %d',
                2147483647
            ));
        }
        // PQsetChunkedRowsMode is not in libpq 14 (Ubuntu 22.04); match unavailable-build false.
        $frame->returnVar->bool(false);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_set_chunked_rows_size() is not implemented for JIT (#7083)');
    }
}

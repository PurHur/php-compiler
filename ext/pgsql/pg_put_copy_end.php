<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * pg_put_copy_end() — PQputCopyEnd (php-src ext/pgsql; #7083).
 */
final class pg_put_copy_end extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_put_copy_end');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'pg_put_copy_end() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_put_copy_end', 1);
        $error = null;
        if (2 === $argc) {
            $errVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $errVar->type) {
                $error = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_put_copy_end', 1, 'error');
                if ('' === $error) {
                    $error = null;
                }
            }
        }
        $frame->returnVar->int(VmPgsqlNative::putCopyEnd(VmPgsqlConnection::native($conn), $error));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_put_copy_end() is not implemented for JIT (#7083)');
    }
}

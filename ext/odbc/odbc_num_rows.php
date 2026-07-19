<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** odbc_num_rows() — php-src ext/odbc/php_odbc.c (#6293). */
final class odbc_num_rows extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_num_rows');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_num_rows() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_num_rows(): supplied resource is not a valid ODBC result resource');
        }
        $frame->returnVar->int(VmOdbcResult::numRows($res->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_num_rows() is not implemented for JIT in this compiler build (issue #6293)');
    }
}

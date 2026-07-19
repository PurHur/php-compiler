<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** odbc_fetch_row() — php-src ext/odbc/php_odbc.c (#6293). */
final class odbc_fetch_row extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_fetch_row');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_fetch_row() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_fetch_row(): supplied resource is not a valid ODBC result resource');
        }
        $rowNumber = null;
        if (2 === $argc) {
            $rowNumber = $frame->calledArgs[1]->toInt();
        }
        $frame->returnVar->bool(VmOdbcResult::fetchRow($res->toObject(), $rowNumber));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_fetch_row() is not implemented for JIT in this compiler build (issue #6293)');
    }
}

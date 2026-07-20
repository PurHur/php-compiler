<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * odbc_setoption() — php-src ext/odbc/php_odbc.c (#21267).
 */
final class odbc_setoption extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_setoption');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (4 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_setoption() expects exactly 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $handle = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $handle->type) {
            throw new \TypeError('odbc_setoption(): Argument #1 ($odbc) must be of type Odbc\\Connection|Odbc\\Result');
        }
        $obj = $handle->toObject();
        $which = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $option = $frame->calledArgs[2]->resolveIndirect()->toInt();
        $value = $frame->calledArgs[3]->resolveIndirect()->toInt();
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_setoption() requires a VM context');
        }
        $frame->returnVar->bool(VmOdbcCore::setoption($obj, $which, $option, $value, $ctx, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_setoption() is not implemented for JIT (#21267)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** odbc_close_all() — php-src ext/odbc/php_odbc.c (#6293). */
final class odbc_close_all extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_close_all');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_close_all() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        VmOdbcCore::closeAll();
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_close_all() is not implemented for JIT in this compiler build (issue #6293)');
    }
}

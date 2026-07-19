<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** odbc_error() — php-src ext/odbc/php_odbc.c (#6293). */
final class odbc_error extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_error');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_error() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (1 === $argc) {
            $conn = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $conn->type && !VmOdbcConnection::isLive($conn->toObject())) {
                throw new \TypeError('odbc_error(): supplied resource is not a valid ODBC connection resource');
            }
        }
        $frame->returnVar->string(VmOdbcConnection::lastState());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_error() is not implemented for JIT in this compiler build (issue #6293)');
    }
}

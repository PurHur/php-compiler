<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** odbc_close() — php-src ext/odbc/php_odbc.c (#6293). */
final class odbc_close extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_close() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type
            || !VmOdbcConnection::isLive($conn->toObject())
        ) {
            throw new \TypeError('odbc_close(): supplied resource is not a valid ODBC connection resource');
        }
        VmOdbcCore::close($conn->toObject());
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_close() is not implemented for JIT in this compiler build (issue #6293)');
    }
}

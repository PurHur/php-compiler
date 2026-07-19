<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** odbc_exec() — php-src ext/odbc/php_odbc.c (#6293). */
final class odbc_exec extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_exec');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_exec() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type
            || !VmOdbcConnection::isLive($conn->toObject())
        ) {
            throw new \TypeError('odbc_exec(): supplied resource is not a valid ODBC connection resource');
        }
        $query = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'odbc_exec', 1, 'query');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_exec() requires a VM context');
        }
        $result = VmOdbcCore::exec($conn->toObject(), $query, $ctx, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_exec() is not implemented for JIT in this compiler build (issue #6293)');
    }
}

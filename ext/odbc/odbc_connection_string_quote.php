<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * odbc_connection_string_quote() — php-src ext/odbc/odbc_utils.c (#21256).
 */
final class odbc_connection_string_quote extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_connection_string_quote');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_connection_string_quote() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $str = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'odbc_connection_string_quote',
            0,
            'str'
        );
        $frame->returnVar->string(VmOdbcConnstr::quote($str));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'odbc_connection_string_quote() is not implemented for JIT in this compiler build (issue #21256)'
        );
    }
}

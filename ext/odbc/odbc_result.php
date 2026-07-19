<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** odbc_result() — php-src ext/odbc/php_odbc.c (#6293). */
final class odbc_result extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_result');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_result() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_result(): supplied resource is not a valid ODBC result resource');
        }
        $fieldArg = $frame->calledArgs[1]->resolveIndirect();
        $field = Variable::TYPE_STRING === $fieldArg->type
            ? $fieldArg->toString()
            : $fieldArg->toInt();
        $value = VmOdbcResult::field($res->toObject(), $field);
        if (false === $value) {
            $frame->returnVar->bool(false);

            return;
        }
        if (null === $value) {
            $frame->returnVar->null();

            return;
        }
        if (\is_int($value)) {
            $frame->returnVar->int($value);

            return;
        }
        if (\is_float($value)) {
            $frame->returnVar->float($value);

            return;
        }
        $frame->returnVar->string((string) $value);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_result() is not implemented for JIT in this compiler build (issue #6293)');
    }
}

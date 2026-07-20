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
 * odbc_autocommit / odbc_commit / odbc_rollback (php-src ext/odbc/php_odbc.c; #21277).
 */

final class odbc_autocommit extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_autocommit');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_autocommit() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_autocommit(): supplied resource is not a valid ODBC connection resource');
        }
        $enable = null;
        if (2 === $argc) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL === $arg->type) {
                $enable = null;
            } else {
                $enable = $arg->toBool();
            }
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_autocommit() requires a VM context');
        }
        $result = VmOdbcCore::autocommit($conn->toObject(), $enable, $ctx, $frame);
        if (\is_int($result)) {
            $frame->returnVar->int($result);

            return;
        }
        $frame->returnVar->bool($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_autocommit() is not implemented for JIT (#21277)');
    }
}

final class odbc_commit extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_commit');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_commit() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_commit(): supplied resource is not a valid ODBC connection resource');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_commit() requires a VM context');
        }
        $frame->returnVar->bool(VmOdbcCore::transact($conn->toObject(), true, $ctx, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_commit() is not implemented for JIT (#21277)');
    }
}

final class odbc_rollback extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_rollback');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_rollback() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_rollback(): supplied resource is not a valid ODBC connection resource');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_rollback() requires a VM context');
        }
        $frame->returnVar->bool(VmOdbcCore::transact($conn->toObject(), false, $ctx, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_rollback() is not implemented for JIT (#21277)');
    }
}

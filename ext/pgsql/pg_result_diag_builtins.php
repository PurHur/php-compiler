<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pg_result_error / pg_result_error_field / pg_last_oid (php-src ext/pgsql/pgsql.c; #20720).
 */

final class pg_result_error extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_result_error');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_result_error() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResultObject($frame->calledArgs[0], 'pg_result_error', 1);
        $out = VmPgsqlCore::resultError($result);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_result_error() is not implemented for JIT (#20720)');
    }
}

final class pg_result_error_field extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_result_error_field');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_result_error_field() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResultObject($frame->calledArgs[0], 'pg_result_error_field', 1);
        $fieldcode = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $out = VmPgsqlCore::resultErrorField($result, $fieldcode);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        if (null === $out) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_result_error_field() is not implemented for JIT (#20720)');
    }
}

final class pg_last_oid extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_last_oid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_last_oid() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_last_oid', 1);
        $oid = VmPgsqlCore::lastOid($result);
        if (false === $oid) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($oid);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_last_oid() is not implemented for JIT (#20720)');
    }
}

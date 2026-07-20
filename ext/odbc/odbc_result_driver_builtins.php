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

/**
 * odbc_next_result / odbc_data_source / odbc_binmode / odbc_longreadlen /
 * odbc_cursor / odbc_result_all (php-src ext/odbc/php_odbc.c; #21278 / #21307 / #21308).
 */

final class odbc_result_all extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_result_all');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_result_all() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_result_all(): supplied resource is not a valid ODBC result resource');
        }
        $format = '';
        if (2 === $argc) {
            $format = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'odbc_result_all', 1, 'format');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_result_all() requires a VM context');
        }
        // #[\Deprecated(since: '8.1')] — php-src odbc.stub.php
        $ctx->errors->internalDeprecated(
            'Function odbc_result_all() is deprecated since 8.1',
            $ctx,
            $frame
        );
        if (null === $frame->returnVar) {
            VmOdbcCore::resultAll($res->toObject(), $format, $ctx, $frame);

            return;
        }
        $n = VmOdbcCore::resultAll($res->toObject(), $format, $ctx, $frame);
        if (false === $n) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($n);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_result_all() is not implemented for JIT (#21308)');
    }
}

final class odbc_cursor extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_cursor');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_cursor() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_cursor(): supplied resource is not a valid ODBC result resource');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_cursor() requires a VM context');
        }
        $name = VmOdbcCore::cursor($res->toObject(), $ctx, $frame);
        if (false === $name) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($name);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_cursor() is not implemented for JIT (#21307)');
    }
}

final class odbc_next_result extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_next_result');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_next_result() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_next_result(): supplied resource is not a valid ODBC result resource');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_next_result() requires a VM context');
        }
        $frame->returnVar->bool(VmOdbcCore::nextResult($res->toObject(), $ctx, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_next_result() is not implemented for JIT (#21278)');
    }
}

final class odbc_data_source extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_data_source');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_data_source() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_data_source(): supplied resource is not a valid ODBC connection resource');
        }
        $fetchType = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_data_source() requires a VM context');
        }
        $ht = VmOdbcCore::dataSource($conn->toObject(), $fetchType, $ctx, $frame);
        if (null === $ht) {
            $frame->returnVar->null();

            return;
        }
        if (false === $ht) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_data_source() is not implemented for JIT (#21278)');
    }
}

final class odbc_binmode extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_binmode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_binmode() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_binmode(): supplied resource is not a valid ODBC result resource');
        }
        $mode = $frame->calledArgs[1]->resolveIndirect()->toInt();
        VmOdbcCore::binmode($res->toObject(), $mode);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_binmode() is not implemented for JIT (#21278)');
    }
}

final class odbc_longreadlen extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_longreadlen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_longreadlen() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_longreadlen(): supplied resource is not a valid ODBC result resource');
        }
        $length = $frame->calledArgs[1]->resolveIndirect()->toInt();
        VmOdbcCore::longreadlen($res->toObject(), $length);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_longreadlen() is not implemented for JIT (#21278)');
    }
}

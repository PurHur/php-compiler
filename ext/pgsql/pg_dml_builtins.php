<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * pg_insert / pg_update / pg_delete / pg_select (php-src ext/pgsql; #20637).
 */

final class pg_insert extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_insert');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'pg_insert() expects between 3 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_insert', 1);
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_insert', 2, 'table_name');
        $valuesVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $valuesVar->type) {
            throw new \TypeError('pg_insert(): Argument #3 ($values) must be of type array');
        }
        $flags = PgsqlConstants::PGSQL_DML_EXEC;
        if ($argc >= 4) {
            $flags = $frame->calledArgs[3]->resolveIndirect()->toInt();
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_insert() requires a VM context');
        }
        // Zend EXEC path builds SQL then PQexec and returns Result
        $execFlags = $flags | PgsqlConstants::PGSQL_DML_EXEC;
        $out = VmPgsqlCore::insert($conn, $table, $valuesVar->toArray(), $execFlags, $ctx);
        if (isset($out['error'])) {
            $frame->returnVar->bool(false);

            return;
        }
        if (isset($out['sql_out']) && ($flags & PgsqlConstants::PGSQL_DML_STRING) && !($flags & PgsqlConstants::PGSQL_DML_EXEC)) {
            $frame->returnVar->string($out['sql_out']);

            return;
        }
        if (isset($out['sql_out']) && ($flags & PgsqlConstants::PGSQL_DML_STRING) && !isset($out['result'])) {
            $frame->returnVar->string($out['sql_out']);

            return;
        }
        if (isset($out['result'])) {
            $frame->returnVar->object($out['result']->toObject());

            return;
        }
        $frame->returnVar->bool(false);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_insert() is not implemented for JIT (#20637)');
    }
}

final class pg_update extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_update');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'pg_update() expects between 4 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_update', 1);
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_update', 2, 'table_name');
        $valuesVar = $frame->calledArgs[2]->resolveIndirect();
        $condsVar = $frame->calledArgs[3]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $valuesVar->type || Variable::TYPE_ARRAY !== $condsVar->type) {
            throw new \TypeError('pg_update(): values and conditions must be arrays');
        }
        $flags = PgsqlConstants::PGSQL_DML_EXEC;
        if ($argc >= 5) {
            $flags = $frame->calledArgs[4]->resolveIndirect()->toInt();
        }
        $out = VmPgsqlCore::update($conn, $table, $valuesVar->toArray(), $condsVar->toArray(), $flags);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_string($out)) {
            $frame->returnVar->string($out);

            return;
        }
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_update() is not implemented for JIT (#20637)');
    }
}

final class pg_delete extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_delete');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'pg_delete() expects between 3 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_delete', 1);
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_delete', 2, 'table_name');
        $condsVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $condsVar->type) {
            throw new \TypeError('pg_delete(): Argument #3 ($conditions) must be of type array');
        }
        $flags = PgsqlConstants::PGSQL_DML_EXEC;
        if ($argc >= 4) {
            $flags = $frame->calledArgs[3]->resolveIndirect()->toInt();
        }
        $out = VmPgsqlCore::delete($conn, $table, $condsVar->toArray(), $flags);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_string($out)) {
            $frame->returnVar->string($out);

            return;
        }
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_delete() is not implemented for JIT (#20637)');
    }
}

final class pg_select extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_select');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'pg_select() expects between 2 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_select', 1);
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_select', 2, 'table_name');
        $conditions = null;
        $flags = PgsqlConstants::PGSQL_DML_EXEC;
        $mode = PgsqlConstants::PGSQL_ASSOC;
        if ($argc >= 3) {
            $condsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $condsVar->type) {
                throw new \TypeError('pg_select(): Argument #3 ($conditions) must be of type array');
            }
            $conditions = $condsVar->toArray();
        }
        if ($argc >= 4) {
            $flags = $frame->calledArgs[3]->resolveIndirect()->toInt();
        }
        if ($argc >= 5) {
            $mode = $frame->calledArgs[4]->resolveIndirect()->toInt();
        }
        $out = VmPgsqlCore::select($conn, $table, $conditions, $flags, $mode);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_string($out)) {
            $frame->returnVar->string($out);

            return;
        }
        $frame->returnVar->array($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_select() is not implemented for JIT (#20637)');
    }
}

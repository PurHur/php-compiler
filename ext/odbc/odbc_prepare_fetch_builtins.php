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
 * odbc prepare / execute / fetch_* / tables / columns / field_* / free_result
 * (php-src ext/odbc/php_odbc.c; #21258).
 */

final class odbc_prepare extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_prepare');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_prepare() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_prepare(): supplied resource is not a valid ODBC connection resource');
        }
        $query = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'odbc_prepare', 1, 'query');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_prepare() requires a VM context');
        }
        $result = VmOdbcCore::prepare($conn->toObject(), $query, $ctx, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_prepare() is not implemented for JIT (#21258)');
    }
}

final class odbc_execute extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_execute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_execute() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_execute(): supplied resource is not a valid ODBC result resource');
        }
        $params = [];
        if (2 === $argc) {
            $arr = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $arr->type) {
                throw new \TypeError('odbc_execute(): Argument #2 ($params) must be of type array');
            }
            foreach ($arr->toArray()->iterate(true) as $entry) {
                $v = $entry->resolveIndirect();
                if (Variable::TYPE_NULL === $v->type) {
                    $params[] = null;
                } else {
                    $params[] = $v->toString();
                }
            }
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_execute() requires a VM context');
        }
        $frame->returnVar->bool(VmOdbcCore::execute($res->toObject(), $params, $ctx, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_execute() is not implemented for JIT (#21258)');
    }
}

final class odbc_fetch_array extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_fetch_array');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_fetch_array() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_fetch_array(): supplied resource is not a valid ODBC result resource');
        }
        $row = null;
        if (2 === $argc) {
            $rowArg = $frame->calledArgs[1]->resolveIndirect();
            $row = Variable::TYPE_NULL === $rowArg->type ? null : $rowArg->toInt();
        }
        $ht = VmOdbcCore::fetchArray($res->toObject(), $row);
        if (false === $ht) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_fetch_array() is not implemented for JIT (#21258)');
    }
}

final class odbc_fetch_object extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_fetch_object');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_fetch_object() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_fetch_object(): supplied resource is not a valid ODBC result resource');
        }
        $row = null;
        if (2 === $argc) {
            $rowArg = $frame->calledArgs[1]->resolveIndirect();
            $row = Variable::TYPE_NULL === $rowArg->type ? null : $rowArg->toInt();
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_fetch_object() requires a VM context');
        }
        $obj = VmOdbcCore::fetchObject($res->toObject(), $ctx, $row);
        if (false === $obj) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($obj);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_fetch_object() is not implemented for JIT (#21258)');
    }
}

final class odbc_fetch_into extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_fetch_into');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_fetch_into() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_fetch_into(): supplied resource is not a valid ODBC result resource');
        }
        $row = null;
        if (3 === $argc) {
            $rowArg = $frame->calledArgs[2]->resolveIndirect();
            $row = Variable::TYPE_NULL === $rowArg->type ? null : $rowArg->toInt();
        }
        $ht = VmOdbcCore::fetchIntoRow($res->toObject(), $row);
        if (false === $ht) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $target = $frame->calledArgs[1]->resolveIndirect();
        $target->array($ht);
        if (null !== $frame->returnVar) {
            $n = 0;
            foreach ($ht->iterate(true) as $_) {
                ++$n;
            }
            $frame->returnVar->int($n);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_fetch_into() is not implemented for JIT (#21258)');
    }
}

final class odbc_tables extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_tables');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_tables() expects between 1 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_tables(): supplied resource is not a valid ODBC connection resource');
        }
        $catalog = self::nullableStr($frame, 1, 'odbc_tables', 'catalog');
        $schema = self::nullableStr($frame, 2, 'odbc_tables', 'schema');
        $table = self::nullableStr($frame, 3, 'odbc_tables', 'table');
        $types = self::nullableStr($frame, 4, 'odbc_tables', 'types');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_tables() requires a VM context');
        }
        $result = VmOdbcCore::tables($conn->toObject(), $catalog, $schema, $table, $types, $ctx, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_tables() is not implemented for JIT (#21258)');
    }

    private static function nullableStr(Frame $frame, int $idx, string $fn, string $name): ?string
    {
        if (!isset($frame->calledArgs[$idx])) {
            return null;
        }
        $var = $frame->calledArgs[$idx]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $fn, $idx, $name);
    }
}

final class odbc_columns extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_columns');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_columns() expects between 1 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_columns(): supplied resource is not a valid ODBC connection resource');
        }
        $catalog = self::nullableStr($frame, 1, 'odbc_columns', 'catalog');
        $schema = self::nullableStr($frame, 2, 'odbc_columns', 'schema');
        $table = self::nullableStr($frame, 3, 'odbc_columns', 'table');
        $column = self::nullableStr($frame, 4, 'odbc_columns', 'column');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_columns() requires a VM context');
        }
        $result = VmOdbcCore::columns($conn->toObject(), $catalog, $schema, $table, $column, $ctx, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_columns() is not implemented for JIT (#21258)');
    }

    private static function nullableStr(Frame $frame, int $idx, string $fn, string $name): ?string
    {
        if (!isset($frame->calledArgs[$idx])) {
            return null;
        }
        $var = $frame->calledArgs[$idx]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $fn, $idx, $name);
    }
}

final class odbc_num_fields extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_num_fields');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_num_fields() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_num_fields(): supplied resource is not a valid ODBC result resource');
        }
        $frame->returnVar->int(VmOdbcResult::numFields($res->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_num_fields() is not implemented for JIT (#21258)');
    }
}

final class odbc_field_name extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_field_name');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_field_name() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_field_name(): supplied resource is not a valid ODBC result resource');
        }
        $field = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $name = VmOdbcResult::fieldName($res->toObject(), $field);
        if (false === $name) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($name);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_field_name() is not implemented for JIT (#21258)');
    }
}

final class odbc_field_type extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_field_type');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_field_type() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_field_type(): supplied resource is not a valid ODBC result resource');
        }
        $field = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $type = VmOdbcResult::fieldType($res->toObject(), $field);
        if (false === $type) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($type);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_field_type() is not implemented for JIT (#21258)');
    }
}

final class odbc_field_len extends Internal
{
    public function __construct(string $name = 'odbc_field_len')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 2 arguments, %d given',
                $this->name,
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): supplied resource is not a valid ODBC result resource',
                $this->name
            ));
        }
        $field = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $len = VmOdbcResult::fieldLen($res->toObject(), $field, $this->name);
        if (false === $len) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($len);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->name.'() is not implemented for JIT (#21258)');
    }
}

final class odbc_field_scale extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_field_scale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_field_scale() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_field_scale(): supplied resource is not a valid ODBC result resource');
        }
        $field = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $scale = VmOdbcResult::fieldScale($res->toObject(), $field);
        if (false === $scale) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($scale);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_field_scale() is not implemented for JIT (#21306)');
    }
}

final class odbc_field_num extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_field_num');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_field_num() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_field_num(): supplied resource is not a valid ODBC result resource');
        }
        $name = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'odbc_field_num', 1, 'field');
        $num = VmOdbcResult::fieldNum($res->toObject(), $name);
        if (false === $num) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($num);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_field_num() is not implemented for JIT (#21258)');
    }
}

final class odbc_free_result extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_free_result');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_free_result() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $res = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $res->type || !VmOdbcResult::isLive($res->toObject())) {
            throw new \TypeError('odbc_free_result(): supplied resource is not a valid ODBC result resource');
        }
        VmOdbcResult::free($res->toObject());
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_free_result() is not implemented for JIT (#21258)');
    }
}

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
 * pg_copy_* / pg_meta_data / pg_convert / pg_field_* (php-src ext/pgsql; #20629).
 * Loaded via Module::getFunctions() + spine require.
 */

final class pg_copy_to extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_copy_to');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'pg_copy_to() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_copy_to', 1);
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_copy_to', 2, 'table_name');
        $separator = "\t";
        $nullAs = '\\\\N';
        if ($argc >= 3) {
            $separator = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'pg_copy_to', 3, 'separator');
        }
        if ($argc >= 4) {
            $nullAs = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'pg_copy_to', 4, 'null_as');
        }
        $rows = VmPgsqlCore::copyTo($conn, $table, $separator, $nullAs);
        if (false === $rows) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($rows);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_copy_to() is not implemented for JIT (#20629)');
    }
}

final class pg_copy_from extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_copy_from');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'pg_copy_from() expects between 3 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_copy_from', 1);
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_copy_from', 2, 'table_name');
        $rowsVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $rowsVar->type) {
            throw new \TypeError(\sprintf(
                'pg_copy_from(): Argument #3 ($rows) must be of type array|Traversable, %s given',
                match ($rowsVar->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_FLOAT => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_OBJECT => 'object',
                    Variable::TYPE_RESOURCE => 'resource',
                    default => 'mixed',
                }
            ));
        }
        $separator = "\t";
        $nullAs = '\\\\N';
        if ($argc >= 4) {
            $separator = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'pg_copy_from', 4, 'separator');
        }
        if ($argc >= 5) {
            $nullAs = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'pg_copy_from', 5, 'null_as');
        }
        $ok = VmPgsqlCore::copyFrom($conn, $table, $rowsVar->toArray(), $separator, $nullAs);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_copy_from() is not implemented for JIT (#20629)');
    }
}

final class pg_meta_data extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_meta_data');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'pg_meta_data() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_meta_data', 1);
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_meta_data', 2, 'table_name');
        $extended = false;
        if ($argc >= 3) {
            $extended = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $meta = VmPgsqlCore::metaData($conn, $table, $extended);
        if (false === $meta) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($meta);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_meta_data() is not implemented for JIT (#20629)');
    }
}

final class pg_convert extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_convert');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'pg_convert() expects between 3 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = VmPgsqlArg::requireConnection($frame->calledArgs[0], 'pg_convert', 1);
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_convert', 2, 'table_name');
        $valuesVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $valuesVar->type) {
            throw new \TypeError(\sprintf(
                'pg_convert(): Argument #3 ($values) must be of type array, %s given',
                match ($valuesVar->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_FLOAT => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_OBJECT => 'object',
                    Variable::TYPE_RESOURCE => 'resource',
                    default => 'mixed',
                }
            ));
        }
        $flags = 0;
        if ($argc >= 4) {
            $flags = $frame->calledArgs[3]->resolveIndirect()->toInt();
        }
        $converted = VmPgsqlCore::convert($conn, $table, $valuesVar->toArray(), $flags);
        if (false === $converted) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($converted);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_convert() is not implemented for JIT (#20629)');
    }
}

final class pg_field_table extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_field_table');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'pg_field_table() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_field_table', 1);
        $field = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $oidOnly = false;
        if ($argc >= 3) {
            $oidOnly = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $out = VmPgsqlCore::fieldTable($result, $field, $oidOnly);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_int($out)) {
            $frame->returnVar->int($out);

            return;
        }
        $frame->returnVar->string($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_field_table() is not implemented for JIT (#20629)');
    }
}

final class pg_field_type_oid extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_field_type_oid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_field_type_oid() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_field_type_oid', 1);
        $field = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $frame->returnVar->int(VmPgsqlCore::fieldTypeOid($result, $field));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_field_type_oid() is not implemented for JIT (#20629)');
    }
}

final class pg_field_is_null extends Internal
{
    public function __construct(string $name = 'pg_field_is_null')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'pg_field_is_null() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_field_is_null', 1);
        if (2 === $argc) {
            @\trigger_error(
                'Calling pg_field_is_null() with 2 arguments is deprecated, use the 3-parameter signature with a null $row parameter instead',
                \E_USER_DEPRECATED
            );
            $row = null;
            $fieldArg = $frame->calledArgs[1]->resolveIndirect();
        } else {
            $rowArg = $frame->calledArgs[1]->resolveIndirect();
            $row = Variable::TYPE_NULL === $rowArg->type ? null : $rowArg->toInt();
            $fieldArg = $frame->calledArgs[2]->resolveIndirect();
        }
        $field = Variable::TYPE_STRING === $fieldArg->type
            ? $fieldArg->toString()
            : $fieldArg->toInt();
        $out = VmPgsqlCore::fieldIsNull($result, $row, $field);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_field_is_null() is not implemented for JIT (#20629)');
    }
}

/**
 * pg_field_name (php-src ext/pgsql/pgsql.c; #20703).
 */
final class pg_field_name extends Internal
{
    public function __construct(string $name = 'pg_field_name')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_field_name() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_field_name', 1);
        $field = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $frame->returnVar->string(VmPgsqlCore::fieldName($result, $field));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_field_name() is not implemented for JIT (#20703)');
    }
}

/**
 * pg_field_size (php-src ext/pgsql/pgsql.c; #20703).
 */
final class pg_field_size extends Internal
{
    public function __construct(string $name = 'pg_field_size')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_field_size() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_field_size', 1);
        $field = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $frame->returnVar->int(VmPgsqlCore::fieldSize($result, $field));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_field_size() is not implemented for JIT (#20703)');
    }
}

/**
 * pg_field_type (php-src ext/pgsql/pgsql.c; #20703).
 */
final class pg_field_type extends Internal
{
    public function __construct(string $name = 'pg_field_type')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_field_type() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_field_type', 1);
        $field = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $frame->returnVar->string(VmPgsqlCore::fieldType($result, $field));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_field_type() is not implemented for JIT (#20703)');
    }
}

/**
 * pg_field_num (php-src ext/pgsql/pgsql.c; #20703).
 */
final class pg_field_num extends Internal
{
    public function __construct(string $name = 'pg_field_num')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_field_num() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_field_num', 1);
        $field = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pg_field_num', 1, 'field');
        $frame->returnVar->int(VmPgsqlCore::fieldNum($result, $field));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_field_num() is not implemented for JIT (#20703)');
    }
}

/**
 * pg_field_prtlen (php-src ext/pgsql/pgsql.c; #20703).
 */
final class pg_field_prtlen extends Internal
{
    public function __construct(string $name = 'pg_field_prtlen')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'pg_field_prtlen() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_field_prtlen', 1);
        if (2 === $argc) {
            @\trigger_error(
                'Calling pg_field_prtlen() with 2 arguments is deprecated, use the 3-parameter signature with a null $row parameter instead',
                \E_USER_DEPRECATED
            );
            $row = null;
            $fieldArg = $frame->calledArgs[1]->resolveIndirect();
        } else {
            $rowArg = $frame->calledArgs[1]->resolveIndirect();
            $row = Variable::TYPE_NULL === $rowArg->type ? null : $rowArg->toInt();
            $fieldArg = $frame->calledArgs[2]->resolveIndirect();
        }
        $field = Variable::TYPE_STRING === $fieldArg->type
            ? $fieldArg->toString()
            : $fieldArg->toInt();
        $out = VmPgsqlCore::fieldPrtlen($result, $row, $field);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_field_prtlen() is not implemented for JIT (#20703)');
    }
}

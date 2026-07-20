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
 * odbc catalog metadata: primarykeys/foreignkeys/statistics/gettypeinfo
 * (php-src ext/odbc/php_odbc.c; #21279).
 */

final class odbc_primarykeys extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_primarykeys');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (4 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_primarykeys() expects exactly 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_primarykeys(): supplied resource is not a valid ODBC connection resource');
        }
        $catalog = self::nullableString($frame->calledArgs[1], 'odbc_primarykeys', 1, 'catalog');
        $schema = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'odbc_primarykeys', 2, 'schema');
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'odbc_primarykeys', 3, 'table');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_primarykeys() requires a VM context');
        }
        $result = VmOdbcCore::primaryKeys($conn->toObject(), $catalog, $schema, $table, $ctx, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_primarykeys() is not implemented for JIT (#21279)');
    }

    private static function nullableString(Variable $var, string $fn, int $idx, string $name): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $fn, $idx, $name);
    }
}

final class odbc_foreignkeys extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_foreignkeys');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (7 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_foreignkeys() expects exactly 7 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_foreignkeys(): supplied resource is not a valid ODBC connection resource');
        }
        $pkCatalog = self::nullableString($frame->calledArgs[1], 'odbc_foreignkeys', 1, 'pk_catalog');
        $pkSchema = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'odbc_foreignkeys', 2, 'pk_schema');
        $pkTable = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'odbc_foreignkeys', 3, 'pk_table');
        $fkCatalog = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'odbc_foreignkeys', 4, 'fk_catalog');
        $fkSchema = VmString::coerceStringBuiltinArg($frame->calledArgs[5], 'odbc_foreignkeys', 5, 'fk_schema');
        $fkTable = VmString::coerceStringBuiltinArg($frame->calledArgs[6], 'odbc_foreignkeys', 6, 'fk_table');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_foreignkeys() requires a VM context');
        }
        $result = VmOdbcCore::foreignKeys(
            $conn->toObject(),
            $pkCatalog,
            $pkSchema,
            $pkTable,
            $fkCatalog,
            $fkSchema,
            $fkTable,
            $ctx,
            $frame
        );
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_foreignkeys() is not implemented for JIT (#21279)');
    }

    private static function nullableString(Variable $var, string $fn, int $idx, string $name): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $fn, $idx, $name);
    }
}

final class odbc_statistics extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_statistics');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (6 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_statistics() expects exactly 6 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_statistics(): supplied resource is not a valid ODBC connection resource');
        }
        $catalog = self::nullableString($frame->calledArgs[1], 'odbc_statistics', 1, 'catalog');
        $schema = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'odbc_statistics', 2, 'schema');
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'odbc_statistics', 3, 'table');
        $unique = $frame->calledArgs[4]->resolveIndirect()->toInt();
        $accuracy = $frame->calledArgs[5]->resolveIndirect()->toInt();
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_statistics() requires a VM context');
        }
        $result = VmOdbcCore::statistics(
            $conn->toObject(),
            $catalog,
            $schema,
            $table,
            $unique,
            $accuracy,
            $ctx,
            $frame
        );
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_statistics() is not implemented for JIT (#21279)');
    }

    private static function nullableString(Variable $var, string $fn, int $idx, string $name): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $fn, $idx, $name);
    }
}

final class odbc_gettypeinfo extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_gettypeinfo');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_gettypeinfo() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_gettypeinfo(): supplied resource is not a valid ODBC connection resource');
        }
        // SQL_ALL_TYPES = 0
        $dataType = 0;
        if (2 === $argc) {
            $dataType = $frame->calledArgs[1]->resolveIndirect()->toInt();
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_gettypeinfo() requires a VM context');
        }
        $result = VmOdbcCore::getTypeInfo($conn->toObject(), $dataType, $ctx, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('odbc_gettypeinfo() is not implemented for JIT (#21279)');
    }
}

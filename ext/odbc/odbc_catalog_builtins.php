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
 * + specialcolumns/procedures/procedurecolumns
 * + tableprivileges/columnprivileges
 * (php-src ext/odbc/php_odbc.c; #21279 / #21294 / #21295).
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

final class odbc_specialcolumns extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_specialcolumns');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (7 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_specialcolumns() expects exactly 7 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_specialcolumns(): supplied resource is not a valid ODBC connection resource');
        }
        $type = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $catalog = self::nullableString($frame->calledArgs[2], 'odbc_specialcolumns', 2, 'catalog');
        $schema = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'odbc_specialcolumns', 3, 'schema');
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'odbc_specialcolumns', 4, 'table');
        $scope = $frame->calledArgs[5]->resolveIndirect()->toInt();
        $nullable = $frame->calledArgs[6]->resolveIndirect()->toInt();
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_specialcolumns() requires a VM context');
        }
        $result = VmOdbcCore::specialColumns(
            $conn->toObject(),
            $type,
            $catalog,
            $schema,
            $table,
            $scope,
            $nullable,
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
        throw new \LogicException('odbc_specialcolumns() is not implemented for JIT (#21294)');
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

final class odbc_procedures extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_procedures');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_procedures() expects between 1 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_procedures(): supplied resource is not a valid ODBC connection resource');
        }
        $catalog = self::nullableStr($frame, 1, 'odbc_procedures', 'catalog');
        $schema = self::nullableStr($frame, 2, 'odbc_procedures', 'schema');
        $procedure = self::nullableStr($frame, 3, 'odbc_procedures', 'procedure');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_procedures() requires a VM context');
        }
        $result = VmOdbcCore::procedures(
            $conn->toObject(),
            $catalog,
            $schema,
            $procedure,
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
        throw new \LogicException('odbc_procedures() is not implemented for JIT (#21294)');
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

final class odbc_procedurecolumns extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_procedurecolumns');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_procedurecolumns() expects between 1 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_procedurecolumns(): supplied resource is not a valid ODBC connection resource');
        }
        $catalog = self::nullableStr($frame, 1, 'odbc_procedurecolumns', 'catalog');
        $schema = self::nullableStr($frame, 2, 'odbc_procedurecolumns', 'schema');
        $procedure = self::nullableStr($frame, 3, 'odbc_procedurecolumns', 'procedure');
        $column = self::nullableStr($frame, 4, 'odbc_procedurecolumns', 'column');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_procedurecolumns() requires a VM context');
        }
        $result = VmOdbcCore::procedureColumns(
            $conn->toObject(),
            $catalog,
            $schema,
            $procedure,
            $column,
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
        throw new \LogicException('odbc_procedurecolumns() is not implemented for JIT (#21294)');
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

final class odbc_tableprivileges extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_tableprivileges');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (4 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_tableprivileges() expects exactly 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_tableprivileges(): supplied resource is not a valid ODBC connection resource');
        }
        $catalog = self::nullableString($frame->calledArgs[1], 'odbc_tableprivileges', 1, 'catalog');
        $schema = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'odbc_tableprivileges', 2, 'schema');
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'odbc_tableprivileges', 3, 'table');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_tableprivileges() requires a VM context');
        }
        $result = VmOdbcCore::tablePrivileges(
            $conn->toObject(),
            $catalog,
            $schema,
            $table,
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
        throw new \LogicException('odbc_tableprivileges() is not implemented for JIT (#21295)');
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

final class odbc_columnprivileges extends Internal
{
    public function __construct()
    {
        parent::__construct('odbc_columnprivileges');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (5 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'odbc_columnprivileges() expects exactly 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type || !VmOdbcConnection::isLive($conn->toObject())) {
            throw new \TypeError('odbc_columnprivileges(): supplied resource is not a valid ODBC connection resource');
        }
        $catalog = self::nullableString($frame->calledArgs[1], 'odbc_columnprivileges', 1, 'catalog');
        $schema = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'odbc_columnprivileges', 2, 'schema');
        $table = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'odbc_columnprivileges', 3, 'table');
        $column = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'odbc_columnprivileges', 4, 'column');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('odbc_columnprivileges() requires a VM context');
        }
        $result = VmOdbcCore::columnPrivileges(
            $conn->toObject(),
            $catalog,
            $schema,
            $table,
            $column,
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
        throw new \LogicException('odbc_columnprivileges() is not implemented for JIT (#21295)');
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

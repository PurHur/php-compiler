<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * NestedJIT SQLite3Stmt methods (#36010 leftover of #36001; bindParam/readOnly #19854).
 * php-src: ext/sqlite3/sqlite3.c zim_SQLite3Stmt_*
 */
final class JitSqlite3Stmt
{
    /** @var array<int, list<array{param: int, var: JITVariable}>> Deferred bindParam() by fold stmt id. */
    private static array $deferredParamBinds = [];

    public static function getSQL(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3Stmt::getSQL', 0, 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $sqlVar = $context->type->object->propertyFetch(
            $obj,
            Sqlite3JitSupport::STMT_CLASS,
            Sqlite3JitSupport::STMT_PROP_SQL
        );
        $str = $context->helper->loadValue($sqlVar);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $str);

        return $ptr;
    }

    public static function paramCount(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3Stmt::paramCount', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $handleVar = $context->type->object->propertyFetch(
            $obj,
            Sqlite3JitSupport::STMT_CLASS,
            Sqlite3JitSupport::STMT_PROP_PARAM_COUNT
        );

        return self::boxLong($context, $context->helper->loadValue($handleVar));
    }

    public static function readOnly(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3Stmt::readOnly', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $roVar = $context->type->object->propertyFetch(
            $obj,
            Sqlite3JitSupport::STMT_CLASS,
            Sqlite3JitSupport::STMT_PROP_READONLY
        );
        $ro = $context->helper->loadValue($roVar);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->icmp(\PHPLLVM\Builder::INT_NE, $ro, $context->getTypeFromString('int64')->constInt(0, false))
        );

        return JitValueBox::pointer($context, $slot);
    }

    public static function bindValue(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3Stmt::bindValue', 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $foldId = JitSqlite3::lastFoldStmtId();
        $param = self::resolveParamIndex($foldId, $args[1]);
        if (null === $param) {
            JitLongArg::lower($context, $args[1], 'SQLite3Stmt::bindValue(): Argument #1 ($param)');
        }
        $valueLit = self::scalarLiteral($args[2]);
        if (null !== $param && null !== $valueLit && $foldId > 0) {
            Sqlite3AotFoldState::bindValue($foldId, $param, $valueLit);
        }

        return self::boxBool($context, true);
    }

    public static function bindParam(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3Stmt::bindParam', 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $foldId = JitSqlite3::lastFoldStmtId();
        $param = self::resolveParamIndex($foldId, $args[1]);
        if (null === $param) {
            JitLongArg::lower($context, $args[1], 'SQLite3Stmt::bindParam(): Argument #1 ($param)');
        }
        if (null !== $param && $foldId > 0) {
            self::$deferredParamBinds[$foldId][] = ['param' => $param, 'var' => $args[2]];
        }

        return self::boxBool($context, true);
    }

    public static function execute(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3Stmt::execute', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $foldId = JitSqlite3::lastFoldStmtId();
        $rowCount = 0;
        if ($foldId > 0) {
            self::applyDeferredParamBinds($foldId);
            $sql = self::stmtSqlLiteral($foldId);
            if (null !== $sql && Sqlite3AotFoldState::isSelectSql($sql)) {
                $rows = Sqlite3AotFoldState::stmtQueryRows($foldId);
                $rowCount = count($rows);
                JitSqlite3Result::registerFoldedRows($rows);
            } else {
                Sqlite3AotFoldState::stmtExecute($foldId);
            }
        }
        $objectType = $context->type->object;
        $classId = $objectType->lookup(Sqlite3JitSupport::RESULT_CLASS);
        $result = $objectType->allocate($classId);
        $objectType->markObjectConstructed($result);
        $i64 = $context->getTypeFromString('int64');
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $result,
            Sqlite3JitSupport::RESULT_CLASS,
            Sqlite3JitSupport::RESULT_PROP_CURSOR,
            $i64->constInt(0, false)
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $result,
            Sqlite3JitSupport::RESULT_CLASS,
            Sqlite3JitSupport::RESULT_PROP_ROW_COUNT,
            $i64->constInt($rowCount, false)
        );

        return self::boxObject($context, $result);
    }

    private static function applyDeferredParamBinds(int $foldId): void
    {
        foreach (self::$deferredParamBinds[$foldId] ?? [] as $entry) {
            $value = self::scalarLiteral($entry['var']);
            if (null !== $value) {
                Sqlite3AotFoldState::bindValue($foldId, $entry['param'], $value);
            }
        }
    }

    private static function stmtSqlLiteral(int $foldId): ?string
    {
        return Sqlite3AotFoldState::stmtSql($foldId);
    }

    private static function resolveParamIndex(int $foldId, JITVariable $paramArg): ?int
    {
        if ($foldId <= 0) {
            return null;
        }
        $lit = $paramArg->compileTimeLong ?? null;
        if (null !== $lit) {
            $n = (int) $lit;

            return $n >= 1 ? $n : null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($paramArg) ?? $paramArg->compileTimeString;
        if (null !== $name) {
            $idx = Sqlite3AotFoldState::bindParameterIndex($foldId, $name);

            return $idx >= 1 ? $idx : null;
        }

        return null;
    }

    private static function scalarLiteral(JITVariable $var): mixed
    {
        if (null !== ($lit = $var->compileTimeString ?? null)) {
            return $lit;
        }
        if (null !== ($lit = $var->compileTimeLong ?? null)) {
            return (int) $lit;
        }
        if (null !== ($lit = JitStringBuiltinArg::compileTimeLiteral($var))) {
            return $lit;
        }
        if (null !== ($lit = $var->compileTimeFloat ?? null)) {
            return $lit;
        }

        return null;
    }

    private static function boxBool(Context $context, bool $v): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt($v ? 1 : 0, false)
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxObject(Context $context, Value $obj): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }

    private static function loadLong(Context $context, Value $obj, string $prop): Value
    {
        $handleVar = $context->type->object->propertyFetch(
            $obj,
            Sqlite3JitSupport::STMT_CLASS,
            $prop
        );

        return $context->helper->loadValue($handleVar);
    }

    private static function readObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function boxLong(Context $context, Value $long): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $long
        );

        return JitValueBox::pointer($context, $slot);
    }
}

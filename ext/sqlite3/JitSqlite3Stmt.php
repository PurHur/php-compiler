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
 * NestedJIT SQLite3Stmt methods (#36010 leftover of #36001).
 * php-src: ext/sqlite3/sqlite3.c zim_SQLite3Stmt_*
 */
final class JitSqlite3Stmt
{
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

    public static function bindValue(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3Stmt::bindValue', 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $param = $args[1]->compileTimeLong ?? null;
        if (null === $param) {
            JitLongArg::lower($context, $args[1], 'SQLite3Stmt::bindValue(): Argument #1 ($param)');
        }
        $valueLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null !== $param && null !== $valueLit && JitSqlite3::lastFoldStmtId() > 0) {
            Sqlite3AotFoldState::bindValue(JitSqlite3::lastFoldStmtId(), (int) $param, $valueLit);
        }

        return self::boxBool($context, true);
    }

    public static function execute(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3Stmt::execute', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        if (JitSqlite3::lastFoldStmtId() > 0) {
            Sqlite3AotFoldState::stmtExecute(JitSqlite3::lastFoldStmtId());
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
            $i64->constInt(0, false)
        );

        return self::boxObject($context, $result);
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

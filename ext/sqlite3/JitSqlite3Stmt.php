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

    /**
     * SQLite3Stmt::bindValue leftover of prepare (#36018 / #36010).
     * php-src zim_SQLite3Stmt_bindValue — fold compile-time scalars onto NestedJIT props.
     */
    public static function bindValue(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3Stmt::bindValue', 2, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        JitLongArg::lower($context, $args[1], 'SQLite3Stmt::bindValue(): Argument #1 ($param)');
        $lit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $lit) {
            throw new \LogicException(
                'SQLite3Stmt::bindValue() user-script AOT requires a compile-time string (#36018)'
            );
        }
        $sqlVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            JitStringBuiltinArg::lower($context, $args[2], 'SQLite3Stmt::bindValue', 2, 'value')
        );
        $context->type->object->storeInstanceProperty(
            $obj,
            Sqlite3JitSupport::STMT_CLASS,
            Sqlite3JitSupport::STMT_PROP_BOUND,
            $sqlVar
        );

        return self::boxBool($context, true);
    }

    /**
     * SQLite3Stmt::execute leftover of bindValue (#36018 / #36010).
     * php-src zim_SQLite3Stmt_execute — fold compile-time INSERT onto parent SQLite3 props.
     */
    public static function execute(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3Stmt::execute', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $stmt = self::readObject($context, $args[0]);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(Sqlite3JitSupport::RESULT_CLASS);
        $result = $objectType->allocate($classId);
        $objectType->markObjectConstructed($result);
        $i64 = $context->getTypeFromString('int64');
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $result,
            Sqlite3JitSupport::RESULT_CLASS,
            Sqlite3JitSupport::RESULT_PROP_HAS,
            $i64->constInt(0, false)
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $result,
            Sqlite3JitSupport::RESULT_CLASS,
            Sqlite3JitSupport::RESULT_PROP_ROW,
            $i64->constInt(0, false)
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $result,
            Sqlite3JitSupport::RESULT_CLASS,
            Sqlite3JitSupport::RESULT_PROP_FETCHED,
            $i64->constInt(0, false)
        );

        return self::boxObject($context, $result);
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
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * LLVM lowering for SQLite3Stmt::getSQL (#36010 leftover of #36001).
 *
 * php-src: ext/sqlite3/sqlite3.c zim_sqlite3_stmt_getsql
 */
final class JitSqlite3Stmt
{
    public static function getSQL(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3Stmt::getSQL', 0, 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        if (\count($args) >= 2) {
            JitStringBuiltinArg::lower($context, $args[1], 'SQLite3Stmt::getSQL', 1, 'expand');
        }
        $sqlPtr = self::loadString($context, $obj, Sqlite3JitSupport::STMT_PROP_SQL);

        return self::boxStringPtr($context, $sqlPtr);
    }

    public static function allocateStmt(Context $context, string $sql): Value
    {
        $objectType = $context->type->object;
        $className = Sqlite3JitSupport::STMT_CLASS;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        self::storeStringLiteral($context, $obj, $className, Sqlite3JitSupport::STMT_PROP_SQL, $sql);

        return $obj;
    }

    private static function readObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function storeStringLiteral(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        string $text
    ): void {
        $strPtr = $context->builder->load($context->constantStringFromString($text));
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strPtr);
        $context->type->object->storeInstanceProperty($obj, $className, $prop, $strVar);
    }

    private static function loadString(Context $context, Value $obj, string $prop): Value
    {
        $strVar = $context->type->object->propertyFetch(
            $obj,
            Sqlite3JitSupport::STMT_CLASS,
            $prop
        );

        return $context->helper->loadValue($strVar);
    }

    private static function boxStringPtr(Context $context, Value $strPtr): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $strPtr);

        return $ptr;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\JIT\Context;
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

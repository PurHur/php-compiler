<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * LLVM lowering for SQLite3Stmt::getSQL (#36010 leftover of #36001).
 *
 * php-src: ext/sqlite3/sqlite3.c — zim_SQLite3Stmt_getSQL
 */
final class JitSqlite3Stmt
{
    public static function getSQL(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3Stmt::getSQL', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = JitSqlite3::readObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            Sqlite3JitSupport::CLASS_STMT,
            Sqlite3JitSupport::PROP_STMT_SQL
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i8p = $context->getTypeFromString('int8*');
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($cstr, $i8p)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );

        return $ptr;
    }
}

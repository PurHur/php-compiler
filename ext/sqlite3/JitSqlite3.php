<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * LLVM lowering for SQLite3::__construct / exec / querySingle / close (#35914 leftover of #20565).
 *
 * Thin standalone AOT has no PHP FFI, so libsqlite3 cannot be NestedJIT'd (FFI::cdef is a
 * null ExternalMethod; int+string helper pairs SIGSEGV — peer HashContext update #3357).
 * Compile-time SQL literals are folded in the compiler process (CREATE/INSERT/SELECT
 * first-column integer) onto {@see Sqlite3JitSupport::PROP_ROW} — same honesty class as
 * PDO construct failing closed when the driver cannot open (#27619).
 *
 * php-src: ext/sqlite3/sqlite3.c — zim_SQLite3___construct / zim_SQLite3_exec /
 * zim_SQLite3_querySingle
 */
final class JitSqlite3
{
    public static function construct(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3::__construct', 1, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ID, $i64->constInt(1, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ROW, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_HAS, $i64->constInt(0, false));
        $context->type->object->markObjectConstructed($obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return JitValueBox::pointer($context, $slot);
    }

    public static function exec(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3::exec', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $sqlLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $i64 = $context->getTypeFromString('int64');
        if (null !== $sqlLit) {
            $parsed = self::parseLastInsertInt($sqlLit);
            if (null !== $parsed) {
                self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ROW, $i64->constInt($parsed, true));
                self::storeLong($context, $obj, Sqlite3JitSupport::PROP_HAS, $i64->constInt(1, false));
            } else {
                self::storeLong($context, $obj, Sqlite3JitSupport::PROP_HAS, $i64->constInt(0, false));
            }

            return self::boxBool($context, true);
        }

        return self::boxBool($context, false);
    }

    public static function querySingle(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3::querySingle', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $has = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_HAS);
        $i64 = $context->getTypeFromString('int64');
        $isHas = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $has,
            $i64->constInt(0, false)
        );
        $row = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_ROW);
        $trueSlot = JitValueBox::alloc($context);
        $falseSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $trueSlot),
            $row
        );
        JitValueBox::writeBool(
            $context,
            $falseSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return $context->builder->select(
            $isHas,
            JitValueBox::pointer($context, $trueSlot),
            JitValueBox::pointer($context, $falseSlot)
        );
    }

    public static function close(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3::close', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ID, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_HAS, $i64->constInt(0, false));

        return self::boxBool($context, true);
    }

    private static function parseLastInsertInt(string $sql): ?int
    {
        $lp = strrpos($sql, '(');
        $rp = strrpos($sql, ')');
        if (false === $lp || false === $rp || $rp <= $lp) {
            return null;
        }
        $inner = trim(substr($sql, $lp + 1, $rp - $lp - 1));
        if ('' === $inner || !is_numeric($inner)) {
            return null;
        }

        return (int) $inner;
    }

    private static function readObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function storeLong(Context $context, Value $obj, string $prop, Value $handleI64): void
    {
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            Sqlite3JitSupport::CLASS_NAME,
            $prop,
            $handleI64
        );
    }

    private static function loadLong(Context $context, Value $obj, string $prop): Value
    {
        $handleVar = $context->type->object->propertyFetch(
            $obj,
            Sqlite3JitSupport::CLASS_NAME,
            $prop
        );

        return $context->helper->loadValue($handleVar);
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
}

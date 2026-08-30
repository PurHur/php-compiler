<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * NestedJIT SQLite3Result::fetchArray (#36010 leftover of #36001).
 * php-src: ext/sqlite3/sqlite3.c zim_SQLite3Result_fetchArray — NUM mode folded first column.
 */
final class JitSqlite3Result
{
    public static function fetchArray(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3Result::fetchArray', 0, 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        if (\count($args) >= 2) {
            JitLongArg::lower($context, $args[1], 'SQLite3Result::fetchArray(): Argument #1 ($mode)');
        }
        $i64 = $context->getTypeFromString('int64');
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);

        $fetched = self::loadLong($context, $obj, Sqlite3JitSupport::RESULT_PROP_FETCHED);
        $already = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $fetched,
            $i64->constInt(0, false)
        );
        $bbFalse = BasicBlockHelper::append($context, 'sqlite3_fetch_false');
        $bbCheck = BasicBlockHelper::append($context, 'sqlite3_fetch_check');
        $bbRow = BasicBlockHelper::append($context, 'sqlite3_fetch_row');
        $bbDone = BasicBlockHelper::append($context, 'sqlite3_fetch_done');
        $context->builder->branchIf($already, $bbFalse, $bbCheck);

        $context->builder->positionAtEnd($bbCheck);
        $has = self::loadLong($context, $obj, Sqlite3JitSupport::RESULT_PROP_HAS);
        $isHas = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $has,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($isHas, $bbRow, $bbFalse);

        $context->builder->positionAtEnd($bbFalse);
        JitValueBox::writeBool(
            $context,
            $resultSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbRow);
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            Sqlite3JitSupport::RESULT_CLASS,
            Sqlite3JitSupport::RESULT_PROP_FETCHED,
            $i64->constInt(1, false)
        );
        $row = self::loadLong($context, $obj, Sqlite3JitSupport::RESULT_PROP_ROW);
        $ht = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $ht,
            $i64->constInt(0, false),
            $row
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $resultPtr,
            $ht
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $resultPtr;
    }

    private static function readObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function loadLong(Context $context, Value $obj, string $prop): Value
    {
        $handleVar = $context->type->object->propertyFetch(
            $obj,
            Sqlite3JitSupport::RESULT_CLASS,
            $prop
        );

        return $context->helper->loadValue($handleVar);
    }
}

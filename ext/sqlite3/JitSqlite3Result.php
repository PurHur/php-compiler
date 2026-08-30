<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for SQLite3Result::fetchArray (#36010 leftover of #36001).
 *
 * php-src: ext/sqlite3/sqlite3.c zim_sqlite3_result_fetcharray
 */
final class JitSqlite3Result
{
    public static function fetchArray(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3Result::fetchArray', 0, 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $className = Sqlite3JitSupport::RESULT_CLASS;
        $i64 = $context->getTypeFromString('int64');
        $mode = $i64->constInt(Sqlite3Constants::BOTH, false);
        if (\count($args) >= 2) {
            $mode = JitLongArg::lower($context, $args[1], 'SQLite3Result::fetchArray(): Argument #1 ($mode)');
        }

        $done = self::loadLong($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_DONE);
        $isDone = $context->builder->icmp(Builder::INT_NE, $done, $i64->constInt(0, false));
        $cursor = self::loadLong($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_CURSOR);
        $count = self::loadLong($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_ROW_COUNT);
        $pastEnd = $context->builder->icmp(Builder::INT_UGE, $cursor, $count);
        $noRow = $context->builder->or($isDone, $pastEnd);

        $bbFalse = BasicBlockHelper::append($context, 'sqlite3_res_false');
        $bbRow = BasicBlockHelper::append($context, 'sqlite3_res_row');
        $bbDone = BasicBlockHelper::append($context, 'sqlite3_res_done');
        $context->builder->branchIf($noRow, $bbFalse, $bbRow);

        $context->builder->positionAtEnd($bbFalse);
        $falseSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $falseSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        $falseTail = $context->builder->getInsertBlock();
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbRow);
        $first = self::loadLong($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_ROW);
        $last = self::loadLong($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_LAST_ROW);
        $isFirst = $context->builder->icmp(Builder::INT_EQ, $cursor, $i64->constInt(0, false));
        $isLastIdx = $context->builder->icmp(
            Builder::INT_EQ,
            $cursor,
            $context->builder->sub($count, $i64->constInt(1, false))
        );
        $multiRow = $context->builder->icmp(Builder::INT_UGT, $count, $i64->constInt(1, false));
        $useLast = $context->builder->and($multiRow, $isLastIdx);
        $cell = $context->builder->select($isFirst, $first, $context->builder->select($useLast, $last, $first));

        $colPtr = self::loadString($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_COL);
        $rowPtr = self::boxFetchRow($context, $cell, $colPtr, $mode);

        $nextCur = $context->builder->add($cursor, $i64->constInt(1, false));
        self::storeLong($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_CURSOR, $nextCur);
        $exhausted = $context->builder->icmp(Builder::INT_UGE, $nextCur, $count);
        self::storeLong(
            $context,
            $obj,
            $className,
            Sqlite3JitSupport::RESULT_PROP_DONE,
            $context->builder->zExt($exhausted, $i64)
        );
        $rowTail = $context->builder->getInsertBlock();
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $phi = $context->builder->phi($falsePtr->typeOf());
        $phi->addIncoming($falsePtr, $falseTail);
        $phi->addIncoming($rowPtr, $rowTail);

        return $phi;
    }

    public static function allocateResult(Context $context, string $col): Value
    {
        $objectType = $context->type->object;
        $className = Sqlite3JitSupport::RESULT_CLASS;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $i64 = $context->getTypeFromString('int64');
        self::storeLong($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_ROW, $i64->constInt(0, false));
        self::storeLong($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_ROW_COUNT, $i64->constInt(0, false));
        self::storeLong($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_LAST_ROW, $i64->constInt(0, false));
        self::storeLong($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_CURSOR, $i64->constInt(0, false));
        self::storeLong($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_DONE, $i64->constInt(0, false));
        self::storeStringLiteral($context, $obj, $className, Sqlite3JitSupport::RESULT_PROP_COL, $col);

        return $obj;
    }

    private static function boxFetchRow(Context $context, Value $cell, Value $colPtr, Value $mode): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $wantAssoc = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($mode, $i64->constInt(Sqlite3Constants::ASSOC, false)),
            $i64->constInt(0, false)
        );
        $wantNum = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($mode, $i64->constInt(Sqlite3Constants::NUM, false)),
            $i64->constInt(0, false)
        );

        $ht = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $cellVar = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $cell);
        $bbNum = BasicBlockHelper::append($context, 'sqlite3_res_num');
        $bbAfterNum = BasicBlockHelper::append($context, 'sqlite3_res_after_num');
        $context->builder->branchIf($wantNum, $bbNum, $bbAfterNum);
        $context->builder->positionAtEnd($bbNum);
        HashTableHelper::setAtIndex($context, $ht, $zero, $cellVar);
        $numTail = $context->builder->getInsertBlock();
        $context->builder->branch($bbAfterNum);

        $context->builder->positionAtEnd($bbAfterNum);
        $bbAssoc = BasicBlockHelper::append($context, 'sqlite3_res_assoc');
        $bbAfterAssoc = BasicBlockHelper::append($context, 'sqlite3_res_after_assoc');
        $context->builder->branchIf($wantAssoc, $bbAssoc, $bbAfterAssoc);
        $context->builder->positionAtEnd($bbAssoc);
        HashTableHelper::setAtStringKey($context, $ht, $colPtr, $cellVar);
        $assocTail = $context->builder->getInsertBlock();
        $context->builder->branch($bbAfterAssoc);
        $context->builder->positionAtEnd($bbAfterAssoc);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
    }

    private static function readObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function storeLong(Context $context, Value $obj, string $className, string $prop, Value $longVal): void
    {
        $handleVar = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $longVal);
        $context->type->object->storeInstanceProperty($obj, $className, $prop, $handleVar);
    }

    private static function loadLong(Context $context, Value $obj, string $className, string $prop): Value
    {
        $handleVar = $context->type->object->propertyFetch($obj, $className, $prop);

        return $context->helper->loadValue($handleVar);
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

    private static function loadString(Context $context, Value $obj, string $className, string $prop): Value
    {
        $strVar = $context->type->object->propertyFetch($obj, $className, $prop);

        return $context->helper->loadValue($strVar);
    }
}

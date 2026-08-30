<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for SQLite3Result::fetchArray (#36010 leftover of #36001).
 *
 * php-src: ext/sqlite3/sqlite3.c — zim_SQLite3Result_fetchArray
 */
final class JitSqlite3Result
{
    public static function fetchArray(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3Result::fetchArray', 0, 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = JitSqlite3::readObjectFromArg($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        if (\count($args) >= 2) {
            $modeVal = null !== $args[1]->compileTimeLong
                ? $i64->constInt((int) $args[1]->compileTimeLong, false)
                : JitLongArg::lower($context, $args[1], 'SQLite3Result::fetchArray(): Argument #1 ($mode)');
            if ('int64' !== $context->getStringFromType($modeVal->typeOf())) {
                $modeVal = $context->builder->sext($modeVal, $i64);
            }
        } else {
            $modeVal = $i64->constInt(Sqlite3Constants::BOTH, false);
        }

        $fetched = JitSqlite3::loadLongOnObject($context, $obj, Sqlite3JitSupport::CLASS_RESULT, Sqlite3JitSupport::PROP_RESULT_FETCHED);
        $has = JitSqlite3::loadLongOnObject($context, $obj, Sqlite3JitSupport::CLASS_RESULT, Sqlite3JitSupport::PROP_RESULT_HAS);
        $alreadyFetched = $context->builder->icmp(Builder::INT_NE, $fetched, $i64->constInt(0, false));
        $noRow = $context->builder->icmp(Builder::INT_EQ, $has, $i64->constInt(0, false));
        $failCond = $context->builder->or($alreadyFetched, $noRow);

        $bbFail = BasicBlockHelper::append($context, 'sqlite3_fetch_fail');
        $bbOk = BasicBlockHelper::append($context, 'sqlite3_fetch_ok');
        $bbDone = BasicBlockHelper::append($context, 'sqlite3_fetch_done');
        $context->builder->branchIf($failCond, $bbFail, $bbOk);

        $context->builder->positionAtEnd($bbFail);
        $failRet = JitSqlite3::boxBoolValue($context, false);
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbOk);
        $rowVal = JitSqlite3::loadLongOnObject($context, $obj, Sqlite3JitSupport::CLASS_RESULT, Sqlite3JitSupport::PROP_RESULT_VAL);
        JitSqlite3::storeLongOnObject($context, $obj, Sqlite3JitSupport::CLASS_RESULT, Sqlite3JitSupport::PROP_RESULT_FETCHED, $i64->constInt(1, false));
        $ht = self::buildRowHashtable($context, $obj, $rowVal, $modeVal);
        $okRet = JitSqlite3::boxHashtable($context, $ht);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $ptrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($ptrTy);
        $phi->addIncoming($failRet, $failTail);
        $phi->addIncoming($okRet, $okTail);

        return $phi;
    }

    private static function buildRowHashtable(Context $context, Value $obj, Value $rowVal, Value $modeI64): Value
    {
        $ht = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $assocBit = $context->builder->and($modeI64, $i64->constInt(Sqlite3Constants::ASSOC, false));
        $numBit = $context->builder->and($modeI64, $i64->constInt(Sqlite3Constants::NUM, false));
        $wantAssoc = $context->builder->icmp(Builder::INT_NE, $assocBit, $zero);
        $wantNum = $context->builder->icmp(Builder::INT_NE, $numBit, $zero);
        $bothDefault = $context->builder->icmp(Builder::INT_EQ, $modeI64, $zero);
        $wantNumFinal = $context->builder->or($wantNum, $bothDefault);
        $wantAssocFinal = $context->builder->or($wantAssoc, $bothDefault);

        $bbEntry = $context->builder->getInsertBlock();
        $bbNum = BasicBlockHelper::append($context, 'sqlite3_fetch_num');
        $bbAfterNum = BasicBlockHelper::append($context, 'sqlite3_fetch_after_num');
        $bbAssoc = BasicBlockHelper::append($context, 'sqlite3_fetch_assoc');
        $bbDone = BasicBlockHelper::append($context, 'sqlite3_fetch_ht_done');
        $context->builder->branchIf($wantNumFinal, $bbNum, $bbAfterNum);

        $context->builder->positionAtEnd($bbNum);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $ht,
            $zero,
            $rowVal
        );
        $numTail = $context->builder->getInsertBlock();
        $context->builder->branch($bbAfterNum);

        $context->builder->positionAtEnd($bbAfterNum);
        $context->builder->branchIf($wantAssocFinal, $bbAssoc, $bbDone);

        $context->builder->positionAtEnd($bbAssoc);
        [$cstr, $len] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            Sqlite3JitSupport::CLASS_RESULT,
            Sqlite3JitSupport::PROP_RESULT_COL
        );
        $i8p = $context->getTypeFromString('int8*');
        $keyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($cstr, $i8p)
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyStr,
            $rowVal
        );
        $assocTail = $context->builder->getInsertBlock();
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $ht;
    }
}

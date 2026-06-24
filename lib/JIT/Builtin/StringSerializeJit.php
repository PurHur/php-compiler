<?php

declare(strict_types=1);

/**
 * LLVM __compiler_serialize (standalone AOT; #9180)_hashtable / __compiler_serialize_value.
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class StringSerializeJit
{
    public static function implement(Context $context): void
    {
        StringSerializeDoubleJit::implement($context);
        self::implementHashtable($context);
        self::implementValue($context);
    }

    private static function implementHashtable(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_serialize_hashtable');
        $entry = $fn->appendBasicBlock('ser_ht_entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valMap = $context->structFieldMap['__value__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroSize = $sizeT->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $ptrSize = $sizeT->constInt(8, false);
        $zeroI64 = $i64->constInt(0, false);

        $countSlot = $context->builder->alloca($sizeT, 1, 'ser_count');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'ser_walk');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'ser_fill_idx');
        $emitIdxSlot = $context->builder->alloca($sizeT, 1, 'ser_emit_idx');
        $resultSlot = $context->builder->alloca($strPtr, 1, 'ser_acc');
        $finalSlot = $context->builder->alloca($strPtr, 1, 'ser_final');
        $nodesSlot = $context->builder->alloca($nodePtrType->pointerType(0), 1, 'ser_nodes_buf');

        $head = $context->builder->load($context->builder->structGep($ht, $htMap['strKeys']));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $head, $nodePtrType->constNull());
        $bbEmpty = $fn->appendBasicBlock('ser_empty');
        $bbWork = $fn->appendBasicBlock('ser_work');
        $bbReturn = $fn->appendBasicBlock('ser_return');
        $context->builder->branchIf($isEmpty, $bbEmpty, $bbWork);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->store(self::literalString($context, 'a:0:{}'), $finalSlot);
        $context->builder->branch($bbReturn);

        $context->builder->positionAtEnd($bbWork);
        $context->builder->store($zeroSize, $countSlot);
        $context->builder->store($head, $walkSlot);
        $countHead = $fn->appendBasicBlock('ser_count_head');
        $countBody = $fn->appendBasicBlock('ser_count_body');
        $countDone = $fn->appendBasicBlock('ser_count_done');
        $context->builder->branch($countHead);
        $context->builder->positionAtEnd($countHead);
        $walkNode = $context->builder->load($walkSlot);
        $walkEnd = $context->builder->icmp(Builder::INT_EQ, $walkNode, $nodePtrType->constNull());
        $context->builder->branchIf($walkEnd, $countDone, $countBody);
        $context->builder->positionAtEnd($countBody);
        $count = $context->builder->load($countSlot);
        $context->builder->store($context->builder->addNoSignedWrap($count, $oneSize), $countSlot);
        $nextWalk = $context->builder->load($context->builder->structGep($walkNode, $nodeMap['next']));
        $context->builder->store($nextWalk, $walkSlot);
        $context->builder->branch($countHead);
        $context->builder->positionAtEnd($countDone);

        $numKeys = $context->builder->load($countSlot);
        $bytes = $context->builder->mulNoSignedWrap($numKeys, $ptrSize);
        $nodesRaw = $context->builder->call($context->lookupFunction('__mm__malloc'), $bytes);
        $nodesArray = $context->builder->pointerCast($nodesRaw, $nodePtrType->pointerType(0));
        $context->builder->store($nodesArray, $nodesSlot);
        $context->builder->store($zeroSize, $idxSlot);
        $context->builder->store($head, $walkSlot);
        $fillHead = $fn->appendBasicBlock('ser_fill_head');
        $fillBody = $fn->appendBasicBlock('ser_fill_body');
        $fillDone = $fn->appendBasicBlock('ser_fill_done');
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillHead);
        $fillNode = $context->builder->load($walkSlot);
        $fillEnd = $context->builder->icmp(Builder::INT_EQ, $fillNode, $nodePtrType->constNull());
        $context->builder->branchIf($fillEnd, $fillDone, $fillBody);
        $context->builder->positionAtEnd($fillBody);
        $idx = $context->builder->load($idxSlot);
        $nodesArray = $context->builder->load($nodesSlot);
        $slotPtr = $context->builder->inBoundsGEP($nodesArray, $idx);
        $context->builder->store($fillNode, $slotPtr);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $oneSize), $idxSlot);
        $nextFill = $context->builder->load($context->builder->structGep($fillNode, $nodeMap['next']));
        $context->builder->store($nextFill, $walkSlot);
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillDone);

        $countStr = self::decimalString($context, $numKeys);
        $acc = JitStringConcat::concat($context, self::literalString($context, 'a:'), $countStr);
        $acc = JitStringConcat::concat($context, $acc, self::literalString($context, ':{'));
        $context->builder->store($acc, $resultSlot);
        $context->builder->store($zeroSize, $emitIdxSlot);

        $emitHead = $fn->appendBasicBlock('ser_emit_head');
        $emitBody = $fn->appendBasicBlock('ser_emit_body');
        $emitDone = $fn->appendBasicBlock('ser_emit_done');
        $context->builder->branch($emitHead);
        $context->builder->positionAtEnd($emitHead);
        $emitIdx = $context->builder->load($emitIdxSlot);
        $emitEnd = $context->builder->icmp(Builder::INT_SGE, $emitIdx, $numKeys);
        $context->builder->branchIf($emitEnd, $emitDone, $emitBody);

        $context->builder->positionAtEnd($emitBody);
        $nodesArray = $context->builder->load($nodesSlot);
        $nodePtr = $context->builder->load($context->builder->inBoundsGEP($nodesArray, $emitIdx));
        $context->builder->store($context->builder->addNoSignedWrap($emitIdx, $oneSize), $emitIdxSlot);

        $nodeKey = $context->builder->load($context->builder->structGep($nodePtr, $nodeMap['key']));
        $acc = $context->builder->load($resultSlot);
        $acc = JitStringConcat::concat($context, $acc, self::serializeQuotedString($context, $nodeKey));
        $valPtr = $context->builder->structGep($nodePtr, $nodeMap['value']);
        $afterVal = $fn->appendBasicBlock('ser_after_val');
        $encodedVal = self::serializeValue(
            $context,
            $fn,
            $valPtr,
            $valMap,
            $i8,
            $i32,
            $i64,
            $i8p,
            $zeroI64,
            $afterVal
        );
        $context->builder->positionAtEnd($afterVal);
        $acc = JitStringConcat::concat($context, $acc, $encodedVal);
        $context->builder->store($acc, $resultSlot);
        $context->builder->branch($emitHead);

        $context->builder->positionAtEnd($emitDone);
        $nodesArray = $context->builder->load($nodesSlot);
        $nodesRaw = $context->builder->pointerCast($nodesArray, $context->getTypeFromString('int8*'));
        $context->builder->call($context->lookupFunction('__mm__free'), $nodesRaw);
        $acc = $context->builder->load($resultSlot);
        $workResult = JitStringConcat::concat($context, $acc, self::literalString($context, '}'));
        $context->builder->store($workResult, $finalSlot);
        $context->builder->branch($bbReturn);

        $context->builder->positionAtEnd($bbReturn);
        $context->builder->returnValue($context->builder->load($finalSlot));
        $context->builder->clearInsertionPosition();
    }

    private static function implementValue(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_serialize_value');
        $entry = $fn->appendBasicBlock('ser_val_entry');
        $context->builder->positionAtEnd($entry);

        $valPtr = $fn->getParam(0);
        $valMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI64 = $i64->constInt(0, false);
        $done = $fn->appendBasicBlock('ser_val_done');
        $result = self::serializeValue(
            $context,
            $fn,
            $valPtr,
            $valMap,
            $i8,
            $i32,
            $i64,
            $i8p,
            $zeroI64,
            $done
        );
        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->getTypeFromString('int64')->constInt(strlen($text), false);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($context->constantFromString($text), $i8p)
        );
    }

    private static function decimalString(Context $context, Value $num): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $numBuf = $context->builder->alloca($i8, $i64->constInt(32, false), 'ser_numbuf');
        $bufC = $context->builder->pointerCast($numBuf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%zu'), $i8p);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $num);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $bufC
        );
    }

    private static function serializeQuotedString(Context $context, Value $str): Value
    {
        $strMap = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($str, $strMap['length']));
        $lenStr = self::decimalString($context, $len);
        $acc = JitStringConcat::concat($context, self::literalString($context, 's:'), $lenStr);
        $acc = JitStringConcat::concat($context, $acc, self::literalString($context, ':"'));
        $acc = JitStringConcat::concat($context, $acc, $str);
        return JitStringConcat::concat($context, $acc, self::literalString($context, '";'));
    }

    /**
     * @param array<string, int> $valMap
     */
    private static function serializeValue(
        Context $context,
        \PHPLLVM\Value\Function_ $fn,
        Value $valPtr,
        array $valMap,
        \PHPLLVM\Type $i8,
        \PHPLLVM\Type $i32,
        \PHPLLVM\Type $i64,
        \PHPLLVM\Type $i8p,
        Value $zeroI64,
        \PHPLLVM\BasicBlock $resumeBlock
    ): Value {
        $strPtr = $context->getTypeFromString('__string__*');
        $bbEntry = $fn->appendBasicBlock('ser_v_entry');
        $context->builder->branch($bbEntry);
        $context->builder->positionAtEnd($bbEntry);

        $resultSlot = $context->builder->alloca($strPtr, 1, 'ser_v_out');
        $numBuf = $context->builder->alloca($i8, $i64->constInt(32, false), 'ser_v_numbuf');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valMap['type'])
        );
        $nullType = $i8->constInt(Variable::TYPE_NULL, false);
        $longType = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $boolType = $i8->constInt(Variable::TYPE_NATIVE_BOOL, false);
        $doubleType = $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false);
        $stringType = $i8->constInt(Variable::TYPE_STRING & 0xff, false);

        $bbNull = $fn->appendBasicBlock('ser_v_null');
        $bbCheckLong = $fn->appendBasicBlock('ser_v_check_long');
        $bbLong = $fn->appendBasicBlock('ser_v_long');
        $bbCheckBool = $fn->appendBasicBlock('ser_v_check_bool');
        $bbBool = $fn->appendBasicBlock('ser_v_bool');
        $bbCheckDouble = $fn->appendBasicBlock('ser_v_check_double');
        $bbDouble = $fn->appendBasicBlock('ser_v_double');
        $bbCheckString = $fn->appendBasicBlock('ser_v_check_string');
        $bbString = $fn->appendBasicBlock('ser_v_string');
        $bbDefault = $fn->appendBasicBlock('ser_v_default');
        $bbDone = $fn->appendBasicBlock('ser_v_done');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullType);
        $context->builder->branchIf($isNull, $bbNull, $bbCheckLong);

        $context->builder->positionAtEnd($bbNull);
        $context->builder->store(self::literalString($context, 'N;'), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckLong);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $longType);
        $context->builder->branchIf($isLong, $bbLong, $bbCheckBool);

        $context->builder->positionAtEnd($bbCheckBool);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolType);
        $context->builder->branchIf($isBool, $bbBool, $bbCheckDouble);

        $context->builder->positionAtEnd($bbCheckDouble);
        $isDouble = $context->builder->icmp(Builder::INT_EQ, $typeByte, $doubleType);
        $context->builder->branchIf($isDouble, $bbDouble, $bbCheckString);

        $context->builder->positionAtEnd($bbCheckString);
        $isStr = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringType);
        $context->builder->branchIf($isStr, $bbString, $bbDefault);

        $context->builder->positionAtEnd($bbDefault);
        $context->builder->store(self::literalString($context, 'N;'), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbLong);
        $num = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valPtr
        );
        $bufC = $context->builder->pointerCast($numBuf, $i8p);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('i:%lld;'),
            $i8p
        );
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $num);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);
        $context->builder->store(
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $lenI64,
                $bufC
            ),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbBool);
        $valueField = $context->builder->structGep($valPtr, $valMap['value']);
        $boolByte = $context->builder->load(
            $context->builder->inBoundsGEP(
                $valueField,
                $i32->constInt(0, false),
                $zeroI64
            )
        );
        $isTrue = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
        $boolStr = $context->builder->select(
            $isTrue,
            self::literalString($context, 'b:1;'),
            self::literalString($context, 'b:0;')
        );
        $context->builder->store($boolStr, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDouble);
        $doubleVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valPtr
        );
        $precision = $context->builder->load(
            $context->module->getNamedGlobal('phpc_ini_serialize_precision')
        );
        $formatted = $context->builder->call(
            $context->lookupFunction('__phpc_format_serialize_double'),
            $doubleVal,
            $precision
        );
        $acc = JitStringConcat::concat($context, self::literalString($context, 'd:'), $formatted);
        $context->builder->store(
            JitStringConcat::concat($context, $acc, self::literalString($context, ';')),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbString);
        $raw = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valPtr
        );
        $context->builder->store(self::serializeQuotedString($context, $raw), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $result = $context->builder->load($resultSlot);
        $context->builder->branch($resumeBlock);

        return $result;
    }
}

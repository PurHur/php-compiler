<?php

declare(strict_types=1);

/**
 * LLVM implementation of __compiler_json_encode_hashtable — JSON object for string-keyed assoc arrays.
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class StringJsonEncode
{
    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_json_encode_hashtable');
        $entry = $fn->appendBasicBlock('je_entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valMap = $context->structFieldMap['__value__'];
        $strMap = $context->structFieldMap['__string__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $twoI64 = $i64->constInt(2, false);

        $sizeT = $context->getTypeFromString('size_t');
        $zeroSize = $sizeT->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $ptrSize = $sizeT->constInt(8, false);

        $countSlot = $context->builder->alloca($sizeT, 1, 'je_count');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'je_walk');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'je_fill_idx');
        $emitIdxSlot = $context->builder->alloca($sizeT, 1, 'je_emit_idx');
        $firstSlot = $context->builder->alloca($i8, 1, 'je_first');
        $resultSlot = $context->builder->alloca($strPtr, 1, 'je_acc');
        $finalSlot = $context->builder->alloca($strPtr, 1, 'je_final');
        $nodesSlot = $context->builder->alloca($nodePtrType->pointerType(0), 1, 'je_nodes_buf');

        $head = $context->builder->load($context->builder->structGep($ht, $htMap['strKeys']));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $head, $nodePtrType->constNull());
        $bbEmpty = $fn->appendBasicBlock('je_empty');
        $bbWork = $fn->appendBasicBlock('je_work');
        $bbReturn = $fn->appendBasicBlock('je_return');
        $context->builder->branchIf($isEmpty, $bbEmpty, $bbWork);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->store(self::literalString($context, '{}'), $finalSlot);
        $context->builder->branch($bbReturn);

        $context->builder->positionAtEnd($bbWork);
        $context->builder->store($zeroSize, $countSlot);
        $context->builder->store($head, $walkSlot);
        $countHead = $fn->appendBasicBlock('je_count_head');
        $countBody = $fn->appendBasicBlock('je_count_body');
        $countDone = $fn->appendBasicBlock('je_count_done');
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
        $fillHead = $fn->appendBasicBlock('je_fill_head');
        $fillBody = $fn->appendBasicBlock('je_fill_body');
        $fillDone = $fn->appendBasicBlock('je_fill_done');
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

        $openBrace = self::literalString($context, '{');
        $context->builder->store($openBrace, $resultSlot);
        $context->builder->store($i8->constInt(1, false), $firstSlot);
        $context->builder->store($zeroSize, $emitIdxSlot);

        $emitHead = $fn->appendBasicBlock('je_emit_head');
        $emitBody = $fn->appendBasicBlock('je_emit_body');
        $emitDone = $fn->appendBasicBlock('je_emit_done');
        $context->builder->branch($emitHead);
        $context->builder->positionAtEnd($emitHead);
        $emitIdx = $context->builder->load($emitIdxSlot);
        $emitEnd = $context->builder->icmp(Builder::INT_SGE, $emitIdx, $numKeys);
        $context->builder->branchIf($emitEnd, $emitDone, $emitBody);

        $context->builder->positionAtEnd($emitBody);
        $nodesArray = $context->builder->load($nodesSlot);
        $nodePtr = $context->builder->load($context->builder->inBoundsGEP($nodesArray, $emitIdx));
        $context->builder->store($context->builder->addNoSignedWrap($emitIdx, $oneSize), $emitIdxSlot);

        $acc = $context->builder->load($resultSlot);
        $isFirst = $context->builder->load($firstSlot);
        $notFirst = $context->builder->icmp(Builder::INT_EQ, $isFirst, $i8->constInt(0, false));
        $bbComma = $fn->appendBasicBlock('je_comma');
        $bbAfterComma = $fn->appendBasicBlock('je_after_comma');
        $context->builder->branchIf($notFirst, $bbComma, $bbAfterComma);
        $context->builder->positionAtEnd($bbComma);
        $comma = self::literalString($context, ',');
        $acc = JitStringConcat::concat($context, $acc, $comma);
        $context->builder->store($acc, $resultSlot);
        $context->builder->branch($bbAfterComma);
        $context->builder->positionAtEnd($bbAfterComma);
        $context->builder->store($i8->constInt(0, false), $firstSlot);

        $nodeKey = $context->builder->load($context->builder->structGep($nodePtr, $nodeMap['key']));
        $quotedKey = self::quoteString($context, $nodeKey);
        $acc = $context->builder->load($resultSlot);
        $acc = JitStringConcat::concat($context, $acc, $quotedKey);
        $colon = self::literalString($context, ':');
        $acc = JitStringConcat::concat($context, $acc, $colon);
        $valPtr = $context->builder->structGep($nodePtr, $nodeMap['value']);
        $afterVal = $fn->appendBasicBlock('je_after_val');
        $encodedVal = self::encodeValue(
            $context,
            $fn,
            $valPtr,
            $valMap,
            $strMap,
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
        $closeBrace = self::literalString($context, '}');
        $acc = $context->builder->load($resultSlot);
        $workResult = JitStringConcat::concat($context, $acc, $closeBrace);
        $context->builder->store($workResult, $finalSlot);
        $context->builder->branch($bbReturn);

        $context->builder->positionAtEnd($bbReturn);
        $context->builder->returnValue($context->builder->load($finalSlot));
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

    private static function quoteString(Context $context, Value $str): Value
    {
        $open = self::literalString($context, '"');
        $close = self::literalString($context, '"');
        $quoted = JitStringConcat::concat($context, $open, $str);

        return JitStringConcat::concat($context, $quoted, $close);
    }

    /**
     * @param array<string, int> $valMap
     * @param array<string, int> $strMap
     */
    private static function encodeValue(
        Context $context,
        \PHPLLVM\Value\Function_ $fn,
        Value $valPtr,
        array $valMap,
        array $strMap,
        \PHPLLVM\Type $i8,
        \PHPLLVM\Type $i32,
        \PHPLLVM\Type $i64,
        \PHPLLVM\Type $i8p,
        Value $zeroI64,
        \PHPLLVM\BasicBlock $resumeBlock
    ): Value {
        $strPtr = $context->getTypeFromString('__string__*');
        $bbEntry = $fn->appendBasicBlock('je_val_entry');
        $context->builder->branch($bbEntry);
        $context->builder->positionAtEnd($bbEntry);

        $resultSlot = $context->builder->alloca($strPtr, 1, 'je_val_out');
        $numBuf = $context->builder->alloca($i8, $i64->constInt(32, false), 'je_numbuf');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valMap['type'])
        );
        $nullType = $i8->constInt(Variable::TYPE_NULL, false);
        $longType = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $boolType = $i8->constInt(Variable::TYPE_NATIVE_BOOL, false);
        $stringType = $i8->constInt(Variable::TYPE_STRING & 0xff, false);

        $bbNull = $fn->appendBasicBlock('je_val_null');
        $bbCheckLong = $fn->appendBasicBlock('je_val_check_long');
        $bbLong = $fn->appendBasicBlock('je_val_long');
        $bbCheckBool = $fn->appendBasicBlock('je_val_check_bool');
        $bbBool = $fn->appendBasicBlock('je_val_bool');
        $bbCheckString = $fn->appendBasicBlock('je_val_check_string');
        $bbString = $fn->appendBasicBlock('je_val_string');
        $bbDefault = $fn->appendBasicBlock('je_val_default');
        $bbDone = $fn->appendBasicBlock('je_val_done');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullType);
        $context->builder->branchIf($isNull, $bbNull, $bbCheckLong);

        $context->builder->positionAtEnd($bbNull);
        $context->builder->store(self::literalString($context, 'null'), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckLong);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $longType);
        $context->builder->branchIf($isLong, $bbLong, $bbCheckBool);

        $context->builder->positionAtEnd($bbCheckBool);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolType);
        $context->builder->branchIf($isBool, $bbBool, $bbCheckString);

        $context->builder->positionAtEnd($bbCheckString);
        $isStr = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringType);
        $context->builder->branchIf($isStr, $bbString, $bbDefault);

        $context->builder->positionAtEnd($bbDefault);
        $context->builder->store(self::literalString($context, 'null'), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbLong);
        $num = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valPtr
        );
        $bufC = $context->builder->pointerCast($numBuf, $i8p);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%lld'),
            $i8p
        );
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $num);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
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
            self::literalString($context, 'true'),
            self::literalString($context, 'false')
        );
        $context->builder->store($boolStr, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbString);
        $raw = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valPtr
        );
        $context->builder->store(self::quoteString($context, $raw), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $result = $context->builder->load($resultSlot);
        $context->builder->branch($resumeBlock);

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM body for __compiler_http_build_query — AOT standalone only (#9443).
 *
 * JIT embed uses {@see HttpBuildQueryJitHelper} PHP; standalone keeps LLVM walker until
 * HashTable iteration compiles in native standalone nested link.
 */
final class StringHttpBuildQueryStandaloneLlvm
{
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_http_build_query');
        $entry = $fn->appendBasicBlock('hbq_entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $numericPrefix = $fn->getParam(1);
        $bracketPrefix = $numericPrefix;
        $argSeparator = $fn->getParam(2);
        $encodingType = $fn->getParam(3);

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

        $useRawSlot = $context->builder->alloca($context->getTypeFromString('int1'), 1, 'hbq_use_raw');
        $rfc3986 = $i64->constInt(2, false);
        $useRaw = $context->builder->icmp(Builder::INT_EQ, $encodingType, $rfc3986);
        $context->builder->store($useRaw, $useRawSlot);

        $countSlot = $context->builder->alloca($sizeT, 1, 'hbq_count');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'hbq_walk');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'hbq_idx');
        $emitIdxSlot = $context->builder->alloca($sizeT, 1, 'hbq_emit_idx');
        $firstSlot = $context->builder->alloca($i8, 1, 'hbq_first');
        $resultSlot = $context->builder->alloca($strPtr, 1, 'hbq_acc');
        $finalSlot = $context->builder->alloca($strPtr, 1, 'hbq_final');
        $nodesSlot = $context->builder->alloca($nodePtrType->pointerType(0), 1, 'hbq_nodes');

        $head = $context->builder->load($context->builder->structGep($ht, $htMap['strKeys']));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $head, $nodePtrType->constNull());
        $bbEmpty = $fn->appendBasicBlock('hbq_empty');
        $bbWork = $fn->appendBasicBlock('hbq_work');
        $bbReturn = $fn->appendBasicBlock('hbq_return');
        $context->builder->branchIf($isEmpty, $bbEmpty, $bbWork);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->store(self::literalString($context, ''), $finalSlot);
        $context->builder->branch($bbReturn);

        $context->builder->positionAtEnd($bbWork);
        $context->builder->store($zeroSize, $countSlot);
        $context->builder->store($head, $walkSlot);
        $countHead = $fn->appendBasicBlock('hbq_count_head');
        $countBody = $fn->appendBasicBlock('hbq_count_body');
        $countDone = $fn->appendBasicBlock('hbq_count_done');
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
        $fillHead = $fn->appendBasicBlock('hbq_fill_head');
        $fillBody = $fn->appendBasicBlock('hbq_fill_body');
        $fillDone = $fn->appendBasicBlock('hbq_fill_done');
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillHead);
        $fillNode = $context->builder->load($walkSlot);
        $fillEnd = $context->builder->icmp(Builder::INT_EQ, $fillNode, $nodePtrType->constNull());
        $context->builder->branchIf($fillEnd, $fillDone, $fillBody);
        $context->builder->positionAtEnd($fillBody);
        $idx = $context->builder->load($idxSlot);
        $nodesArray = $context->builder->load($nodesSlot);
        $context->builder->store($fillNode, $context->builder->inBoundsGEP($nodesArray, $idx));
        $context->builder->store($context->builder->addNoSignedWrap($idx, $oneSize), $idxSlot);
        $nextFill = $context->builder->load($context->builder->structGep($fillNode, $nodeMap['next']));
        $context->builder->store($nextFill, $walkSlot);
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillDone);

        $context->builder->store(self::literalString($context, ''), $resultSlot);
        $context->builder->store($i8->constInt(1, false), $firstSlot);
        $context->builder->store($zeroSize, $emitIdxSlot);

        $emitHead = $fn->appendBasicBlock('hbq_emit_head');
        $emitBody = $fn->appendBasicBlock('hbq_emit_body');
        $emitDone = $fn->appendBasicBlock('hbq_emit_done');
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
        $fullKey = self::buildFullKey($context, $bracketPrefix, $nodeKey);
        $valPtr = $context->builder->structGep($nodePtr, $nodeMap['value']);
        $pairStr = self::emitPair(
            $context,
            $fn,
            $fullKey,
            $valPtr,
            $valMap,
            $useRawSlot,
            $i8,
            $i32,
            $i64,
            $i8p,
            $zeroI64,
            $argSeparator,
            $encodingType
        );

        $acc = $context->builder->load($resultSlot);
        $isFirst = $context->builder->load($firstSlot);
        $notFirst = $context->builder->icmp(Builder::INT_EQ, $isFirst, $i8->constInt(0, false));
        $bbSep = $fn->appendBasicBlock('hbq_sep');
        $bbAfterSep = $fn->appendBasicBlock('hbq_after_sep');
        $context->builder->branchIf($notFirst, $bbSep, $bbAfterSep);
        $context->builder->positionAtEnd($bbSep);
        $acc = JitStringConcat::concat($context, $acc, $argSeparator);
        $context->builder->store($acc, $resultSlot);
        $context->builder->branch($bbAfterSep);
        $context->builder->positionAtEnd($bbAfterSep);
        $context->builder->store($i8->constInt(0, false), $firstSlot);
        $acc = $context->builder->load($resultSlot);
        $context->builder->store(JitStringConcat::concat($context, $acc, $pairStr), $resultSlot);
        $context->builder->branch($emitHead);

        $context->builder->positionAtEnd($emitDone);
        $nodesArray = $context->builder->load($nodesSlot);
        $context->builder->call(
            $context->lookupFunction('__mm__free'),
            $context->builder->pointerCast($nodesArray, $context->getTypeFromString('int8*'))
        );
        $context->builder->store($context->builder->load($resultSlot), $finalSlot);
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

    private static function buildFullKey(Context $context, Value $prefix, Value $key): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $prefix);
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $lastIdx = $context->builder->subNoSignedWrap($len, $i64->constInt(1, false));
        $strMap = $context->structFieldMap['__string__'];
        $prefixData = $context->builder->structGep($prefix, $strMap['value']);
        $lastCharPtr = $context->builder->gep($prefixData, $lastIdx);
        $lastChar = $context->builder->load($lastCharPtr);
        $openBracket = $i8->constInt(ord('['), false);
        $isBracketPrefix = $context->builder->icmp(Builder::INT_EQ, $lastChar, $openBracket);
        $close = self::literalString($context, ']');
        $withBracket = JitStringConcat::concat($context, $prefix, $key);
        $withBracket = JitStringConcat::concat($context, $withBracket, $close);

        return $context->builder->select(
            $isEmpty,
            $key,
            $context->builder->select($isBracketPrefix, $withBracket, $key)
        );
    }

    private static function urlencodeWithFn(Context $context, Value $useRawSlot, Value $str): Value
    {
        $useRaw = $context->builder->load($useRawSlot);
        $form = $context->builder->call($context->lookupFunction('__string__urlencode'), $str);
        $raw = $context->builder->call($context->lookupFunction('__string__rawurlencode'), $str);

        return $context->builder->select($useRaw, $raw, $form);
    }

    /**
     * @param array<string, int> $valMap
     */
    private static function emitPair(
        Context $context,
        \PHPLLVM\Value\Function_ $fn,
        Value $fullKey,
        Value $valPtr,
        array $valMap,
        Value $useRawSlot,
        $i8,
        $i32,
        $i64,
        $i8p,
        Value $zeroI64,
        Value $argSeparator,
        Value $encodingType
    ): Value {
        $strPtr = $context->getTypeFromString('__string__*');
        $typeByte = $context->builder->load($context->builder->structGep($valPtr, $valMap['type']));
        $nullType = $i8->constInt(Variable::TYPE_NULL, false);
        $htType = $i8->constInt(Variable::TYPE_HASHTABLE, false);

        $bbEntry = $fn->appendBasicBlock('hbq_pair_entry');
        $bbNull = $fn->appendBasicBlock('hbq_pair_null');
        $bbCheckHt = $fn->appendBasicBlock('hbq_pair_check_ht');
        $bbNested = $fn->appendBasicBlock('hbq_pair_nested');
        $bbScalar = $fn->appendBasicBlock('hbq_pair_scalar');
        $bbDone = $fn->appendBasicBlock('hbq_pair_done');
        $outSlot = $context->builder->alloca($strPtr, 1, 'hbq_pair_out');

        $context->builder->branch($bbEntry);
        $context->builder->positionAtEnd($bbEntry);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullType);
        $context->builder->branchIf($isNull, $bbNull, $bbCheckHt);

        $context->builder->positionAtEnd($bbNull);
        $context->builder->store(self::literalString($context, ''), $outSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckHt);
        $isHt = $context->builder->icmp(Builder::INT_EQ, $typeByte, $htType);
        $context->builder->branchIf($isHt, $bbNested, $bbScalar);

        $context->builder->positionAtEnd($bbNested);
        $childHt = $context->builder->call($context->lookupFunction('__value__readHashtable'), $valPtr);
        $childPrefix = JitStringConcat::concat($context, $fullKey, self::literalString($context, '['));
        $nested = $context->builder->call(
            $context->lookupFunction('__compiler_http_build_query'),
            $childHt,
            $childPrefix,
            $argSeparator,
            $encodingType
        );
        $context->builder->store($nested, $outSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbScalar);
        $encodedKey = self::urlencodeWithFn($context, $useRawSlot, $fullKey);
        $eq = self::literalString($context, '=');
        $encodedVal = self::encodeScalarValue($context, $fn, $valPtr, $valMap, $useRawSlot, $i8, $i32, $i64, $i8p, $zeroI64);
        $pair = JitStringConcat::concat($context, $encodedKey, $eq);
        $context->builder->store(JitStringConcat::concat($context, $pair, $encodedVal), $outSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($outSlot);
    }

    /**
     * @param array<string, int> $valMap
     */
    private static function encodeScalarValue(
        Context $context,
        \PHPLLVM\Value\Function_ $fn,
        Value $valPtr,
        array $valMap,
        Value $useRawSlot,
        $i8,
        $i32,
        $i64,
        $i8p,
        Value $zeroI64
    ): Value {
        $strPtr = $context->getTypeFromString('__string__*');
        $typeByte = $context->builder->load($context->builder->structGep($valPtr, $valMap['type']));
        $longType = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $boolType = $i8->constInt(Variable::TYPE_NATIVE_BOOL, false);
        $stringType = $i8->constInt(Variable::TYPE_STRING, false);

        $bbEntry = $fn->appendBasicBlock('hbq_val_entry');
        $bbLong = $fn->appendBasicBlock('hbq_val_long');
        $bbBool = $fn->appendBasicBlock('hbq_val_bool');
        $bbString = $fn->appendBasicBlock('hbq_val_string');
        $bbEmpty = $fn->appendBasicBlock('hbq_val_empty');
        $bbDone = $fn->appendBasicBlock('hbq_val_done');
        $outSlot = $context->builder->alloca($strPtr, 1, 'hbq_val_out');

        $context->builder->branch($bbEntry);
        $context->builder->positionAtEnd($bbEntry);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $longType);
        $bbCheckBool = $fn->appendBasicBlock('hbq_val_check_bool');
        $context->builder->branchIf($isLong, $bbLong, $bbCheckBool);

        $context->builder->positionAtEnd($bbCheckBool);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolType);
        $bbCheckStr = $fn->appendBasicBlock('hbq_val_check_str');
        $context->builder->branchIf($isBool, $bbBool, $bbCheckStr);

        $context->builder->positionAtEnd($bbCheckStr);
        $isStr = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringType);
        $context->builder->branchIf($isStr, $bbString, $bbEmpty);

        $context->builder->positionAtEnd($bbLong);
        $numBuf = $context->builder->alloca($i8, $i64->constInt(32, false), 'hbq_numbuf');
        $num = $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr);
        $bufC = $context->builder->pointerCast($numBuf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%lld'), $i8p);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $num);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__string__init'), $lenI64, $bufC),
            $outSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbBool);
        $valueField = $context->builder->structGep($valPtr, $valMap['value']);
        $boolByte = $context->builder->load(
            $context->builder->inBoundsGEP($valueField, $i32->constInt(0, false), $zeroI64)
        );
        $isTrue = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
        $context->builder->store(
            $context->builder->select($isTrue, self::literalString($context, '1'), self::literalString($context, '0')),
            $outSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbString);
        $raw = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $context->builder->store(self::urlencodeWithFn($context, $useRawSlot, $raw), $outSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->store(self::literalString($context, ''), $outSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($outSlot);
    }
}

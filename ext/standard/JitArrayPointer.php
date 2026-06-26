<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for array internal pointer builtins (ext/standard/array.c; #4967, #5504).
 *
 * php-src: {@see https://github.com/php/php-src/blob/master/ext/standard/array.c}
 */
final class JitArrayPointer
{
    private const INVALID_INDEX = -1;

    private static int $copySeq = 0;

    public static function key(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'key');
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $result = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $result);
        self::emitKey($context, $ht, $resultPtr);

        return $result;
    }

    public static function current(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'current');
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $result = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $result);
        self::emitCurrent($context, $ht, $resultPtr);

        return $result;
    }

    public static function next(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'next');
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $result = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $result);
        self::emitNext($context, $ht, $resultPtr);

        return $result;
    }

    public static function prev(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'prev');
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $result = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $result);
        self::emitPrev($context, $ht, $resultPtr);

        return $result;
    }

    public static function reset(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'reset');
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $result = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $result);
        self::emitReset($context, $ht, $resultPtr);

        return $result;
    }

    public static function end(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'end');
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $result = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $result);
        self::emitEnd($context, $ht, $resultPtr);

        return $result;
    }

    private static function requireArrayArg(Context $context, JITVariable $array, string $fn): void
    {
        JitArrayKey::requireArrayArg($context, $array, $fn);
    }

    private static function emitKey(Context $context, Value $ht, Value $resultPtr): void
    {
        $emptyBb = BasicBlockHelper::append($context, 'arr_ptr_key_empty');
        $workBb = BasicBlockHelper::append($context, 'arr_ptr_key_work');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_key_done');
        self::branchIfEmpty($context, $ht, $emptyBb, $workBb);
        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($workBb);
        $packedBb = BasicBlockHelper::append($context, 'arr_ptr_key_packed');
        $stringBb = BasicBlockHelper::append($context, 'arr_ptr_key_string');
        self::branchPackedVsString($context, $ht, $packedBb, $stringBb);
        $context->builder->positionAtEnd($packedBb);
        self::emitPackedKey($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($stringBb);
        self::emitStringKey($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitCurrent(Context $context, Value $ht, Value $resultPtr): void
    {
        $emptyBb = BasicBlockHelper::append($context, 'arr_ptr_cur_empty');
        $workBb = BasicBlockHelper::append($context, 'arr_ptr_cur_work');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_cur_done');
        self::branchIfEmpty($context, $ht, $emptyBb, $workBb);
        $context->builder->positionAtEnd($emptyBb);
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($workBb);
        $packedBb = BasicBlockHelper::append($context, 'arr_ptr_cur_packed');
        $stringBb = BasicBlockHelper::append($context, 'arr_ptr_cur_string');
        self::branchPackedVsString($context, $ht, $packedBb, $stringBb);
        $context->builder->positionAtEnd($packedBb);
        self::emitPackedCurrent($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($stringBb);
        self::emitStringCurrent($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitNext(Context $context, Value $ht, Value $resultPtr): void
    {
        $emptyBb = BasicBlockHelper::append($context, 'arr_ptr_next_empty');
        $workBb = BasicBlockHelper::append($context, 'arr_ptr_next_work');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_next_done');
        self::branchIfEmpty($context, $ht, $emptyBb, $workBb);
        $context->builder->positionAtEnd($emptyBb);
        self::writeFalse($context, $resultPtr);
        self::storeInternalPointer($context, $ht, self::constInvalid($context));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($workBb);
        $packedBb = BasicBlockHelper::append($context, 'arr_ptr_next_packed');
        $stringBb = BasicBlockHelper::append($context, 'arr_ptr_next_string');
        self::branchPackedVsString($context, $ht, $packedBb, $stringBb);
        $context->builder->positionAtEnd($packedBb);
        self::emitPackedNext($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($stringBb);
        self::emitStringNext($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitPrev(Context $context, Value $ht, Value $resultPtr): void
    {
        $emptyBb = BasicBlockHelper::append($context, 'arr_ptr_prev_empty');
        $workBb = BasicBlockHelper::append($context, 'arr_ptr_prev_work');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_prev_done');
        self::branchIfEmpty($context, $ht, $emptyBb, $workBb);
        $context->builder->positionAtEnd($emptyBb);
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($workBb);
        $packedBb = BasicBlockHelper::append($context, 'arr_ptr_prev_packed');
        $stringBb = BasicBlockHelper::append($context, 'arr_ptr_prev_string');
        self::branchPackedVsString($context, $ht, $packedBb, $stringBb);
        $context->builder->positionAtEnd($packedBb);
        self::emitPackedPrev($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($stringBb);
        self::emitStringPrev($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitReset(Context $context, Value $ht, Value $resultPtr): void
    {
        $emptyBb = BasicBlockHelper::append($context, 'arr_ptr_reset_empty');
        $workBb = BasicBlockHelper::append($context, 'arr_ptr_reset_work');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_reset_done');
        self::branchIfEmpty($context, $ht, $emptyBb, $workBb);
        $context->builder->positionAtEnd($emptyBb);
        self::writeFalse($context, $resultPtr);
        self::storeInternalPointer($context, $ht, self::constInvalid($context));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($workBb);
        $packedBb = BasicBlockHelper::append($context, 'arr_ptr_reset_packed');
        $stringBb = BasicBlockHelper::append($context, 'arr_ptr_reset_string');
        self::branchPackedVsString($context, $ht, $packedBb, $stringBb);
        $context->builder->positionAtEnd($packedBb);
        self::emitPackedReset($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($stringBb);
        self::emitStringReset($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitEnd(Context $context, Value $ht, Value $resultPtr): void
    {
        $emptyBb = BasicBlockHelper::append($context, 'arr_ptr_end_empty');
        $workBb = BasicBlockHelper::append($context, 'arr_ptr_end_work');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_end_done');
        self::branchIfEmpty($context, $ht, $emptyBb, $workBb);
        $context->builder->positionAtEnd($emptyBb);
        self::writeFalse($context, $resultPtr);
        self::storeInternalPointer($context, $ht, self::constInvalid($context));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($workBb);
        $packedBb = BasicBlockHelper::append($context, 'arr_ptr_end_packed');
        $stringBb = BasicBlockHelper::append($context, 'arr_ptr_end_string');
        self::branchPackedVsString($context, $ht, $packedBb, $stringBb);
        $context->builder->positionAtEnd($packedBb);
        self::emitPackedEnd($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($stringBb);
        self::emitStringEnd($context, $ht, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function branchIfEmpty(Context $context, Value $ht, $emptyBb, $workBb): void
    {
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);
    }

    private static function branchPackedVsString(Context $context, Value $ht, $packedBb, $stringBb): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        $isPacked = $context->builder->icmp(Builder::INT_NE, $nextFree, $zero);
        $context->builder->branchIf($isPacked, $packedBb, $stringBb);
    }

    private static function emitPackedKey(Context $context, Value $ht, Value $resultPtr): void
    {
        self::emitPackedKeyOrCurrent($context, $ht, $resultPtr, true);
    }

    private static function emitPackedCurrent(Context $context, Value $ht, Value $resultPtr): void
    {
        self::emitPackedKeyOrCurrent($context, $ht, $resultPtr, false);
    }

    private static function emitPackedKeyOrCurrent(Context $context, Value $ht, Value $resultPtr, bool $keyOnly): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $ip = self::loadInternalPointer($context, $ht);
        $invalid = self::constInvalid($context);
        $isInvalid = $context->builder->icmp(Builder::INT_EQ, $ip, $invalid);
        $invBb = BasicBlockHelper::append($context, 'arr_ptr_ppos_inv');
        $useBb = BasicBlockHelper::append($context, 'arr_ptr_ppos_use');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_ppos_done');
        $context->builder->branchIf($isInvalid, $invBb, $useBb);
        $context->builder->positionAtEnd($invBb);
        $idxInv = self::findNextPackedIndex($context, $ht, $zero);
        self::emitPackedKeyOrCurrentAtIndex($context, $ht, $idxInv, $resultPtr, $keyOnly, $doneBb);
        $context->builder->positionAtEnd($useBb);
        $idxUse = $context->builder->truncOrBitCast($ip, $sizeT);
        self::emitPackedKeyOrCurrentAtIndex($context, $ht, $idxUse, $resultPtr, $keyOnly, $doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitPackedKeyOrCurrentAtIndex(
        Context $context,
        Value $ht,
        Value $idx,
        Value $resultPtr,
        bool $keyOnly,
        $doneBb
    ): void {
        $validBb = BasicBlockHelper::append($context, 'arr_ptr_p_at_valid');
        $failBb = BasicBlockHelper::append($context, 'arr_ptr_p_at_fail');
        self::branchIfPackedIndexValid($context, $ht, $idx, $validBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        if ($keyOnly) {
            $context->builder->call($context->lookupFunction('__value__writeNull'), $resultPtr);
        } else {
            self::writeFalse($context, $resultPtr);
        }
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($validBb);
        if ($keyOnly) {
            $i64 = $context->getTypeFromString('int64');
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $resultPtr,
                $context->builder->truncOrBitCast($idx, $i64)
            );
        } else {
            self::copyPackedValueAt($context, $ht, $idx, $resultPtr);
        }
        $context->builder->branch($doneBb);
    }

    private static function emitPackedNext(Context $context, Value $ht, Value $resultPtr): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $zero = $sizeT->constInt(0, false);
        $ip = self::loadInternalPointer($context, $ht);
        $i64 = $context->getTypeFromString('int64');
        $invalid = self::constInvalid($context);
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $ipAsSize = $context->builder->truncOrBitCast($ip, $sizeT);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $ipAsSize, $nextFree);
        $pastBb = BasicBlockHelper::append($context, 'arr_ptr_pnext_past');
        $scanBb = BasicBlockHelper::append($context, 'arr_ptr_pnext_scan');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_pnext_done');
        $context->builder->branchIf($pastEnd, $pastBb, $scanBb);
        $context->builder->positionAtEnd($pastBb);
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($scanBb);
        $isInvalid = $context->builder->icmp(Builder::INT_EQ, $ip, $invalid);
        $startBb = BasicBlockHelper::append($context, 'arr_ptr_pnext_start');
        $advBb = BasicBlockHelper::append($context, 'arr_ptr_pnext_adv');
        $mergeBb = BasicBlockHelper::append($context, 'arr_ptr_pnext_merge');
        $context->builder->branchIf($isInvalid, $startBb, $advBb);
        $context->builder->positionAtEnd($startBb);
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($advBb);
        $advIdx = $context->builder->addNoSignedWrap($ipAsSize, $one);
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($mergeBb);
        $startPhi = $context->builder->phi($sizeT);
        $startPhi->addIncoming($zero, $startBb);
        $startPhi->addIncoming($advIdx, $advBb);
        $foundIdx = self::findNextPackedIndex($context, $ht, $startPhi);
        $foundValidBb = BasicBlockHelper::append($context, 'arr_ptr_pnext_found');
        $foundFailBb = BasicBlockHelper::append($context, 'arr_ptr_pnext_fail');
        self::branchIfPackedIndexValid($context, $ht, $foundIdx, $foundValidBb, $foundFailBb);
        $context->builder->positionAtEnd($foundFailBb);
        self::storeInternalPointer($context, $ht, $context->builder->sextOrBitCast($nextFree, $i64));
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($foundValidBb);
        self::storeInternalPointer($context, $ht, $context->builder->sextOrBitCast($foundIdx, $i64));
        self::copyPackedValueAt($context, $ht, $foundIdx, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitPackedPrev(Context $context, Value $ht, Value $resultPtr): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $ip = self::loadInternalPointer($context, $ht);
        $i64 = $context->getTypeFromString('int64');
        $invalid = self::constInvalid($context);
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $ipAsSize = $context->builder->truncOrBitCast($ip, $sizeT);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $ipAsSize, $nextFree);
        $pastBb = BasicBlockHelper::append($context, 'arr_ptr_pprev_past');
        $workBb = BasicBlockHelper::append($context, 'arr_ptr_pprev_work');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_pprev_done');
        $context->builder->branchIf($pastEnd, $pastBb, $workBb);
        $context->builder->positionAtEnd($pastBb);
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($workBb);
        $isInvalid = $context->builder->icmp(Builder::INT_EQ, $ip, $invalid);
        $failBb2 = BasicBlockHelper::append($context, 'arr_ptr_pprev_inv');
        $scanBb = BasicBlockHelper::append($context, 'arr_ptr_pprev_scan');
        $context->builder->branchIf($isInvalid, $failBb2, $scanBb);
        $context->builder->positionAtEnd($failBb2);
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($scanBb);
        $before = $context->builder->sub($ipAsSize, $one);
        $idx = self::findPrevPackedIndex($context, $ht, $before);
        $validBb2 = BasicBlockHelper::append($context, 'arr_ptr_pprev_valid');
        $failBb3 = BasicBlockHelper::append($context, 'arr_ptr_pprev_fail');
        self::branchIfPackedIndexValid($context, $ht, $idx, $validBb2, $failBb3);
        $context->builder->positionAtEnd($failBb3);
        self::storeInternalPointer($context, $ht, $invalid);
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($validBb2);
        self::storeInternalPointer($context, $ht, $context->builder->sextOrBitCast($idx, $i64));
        self::copyPackedValueAt($context, $ht, $idx, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitPackedReset(Context $context, Value $ht, Value $resultPtr): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $idx = self::findNextPackedIndex($context, $ht, $sizeT->constInt(0, false));
        $validBb = BasicBlockHelper::append($context, 'arr_ptr_preset_valid');
        $failBb = BasicBlockHelper::append($context, 'arr_ptr_preset_fail');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_preset_done');
        self::branchIfPackedIndexValid($context, $ht, $idx, $validBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        self::storeInternalPointer($context, $ht, self::constInvalid($context));
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($validBb);
        self::storeInternalPointer($context, $ht, $context->builder->sextOrBitCast($idx, $context->getTypeFromString('int64')));
        self::copyPackedValueAt($context, $ht, $idx, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitPackedEnd(Context $context, Value $ht, Value $resultPtr): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $idx = self::findPrevPackedIndex($context, $ht, $context->builder->sub($nextFree, $one));
        $validBb = BasicBlockHelper::append($context, 'arr_ptr_pend_valid');
        $failBb = BasicBlockHelper::append($context, 'arr_ptr_pend_fail');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_pend_done');
        self::branchIfPackedIndexValid($context, $ht, $idx, $validBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        self::storeInternalPointer($context, $ht, self::constInvalid($context));
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($validBb);
        self::storeInternalPointer($context, $ht, $context->builder->sextOrBitCast($idx, $context->getTypeFromString('int64')));
        self::copyPackedValueAt($context, $ht, $idx, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitStringKey(Context $context, Value $ht, Value $resultPtr): void
    {
        $node = self::stringPositionNode($context, $ht);
        $validBb = BasicBlockHelper::append($context, 'arr_ptr_skey_valid');
        $nullBb = BasicBlockHelper::append($context, 'arr_ptr_skey_null');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_skey_done');
        self::branchIfStringNodeValid($context, $node, $validBb, $nullBb);
        $context->builder->positionAtEnd($nullBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($validBb);
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $resultPtr,
            $owned
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitStringCurrent(Context $context, Value $ht, Value $resultPtr): void
    {
        $node = self::stringPositionNode($context, $ht);
        $validBb = BasicBlockHelper::append($context, 'arr_ptr_scur_valid');
        $falseBb = BasicBlockHelper::append($context, 'arr_ptr_scur_false');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_scur_done');
        self::branchIfStringNodeValid($context, $node, $validBb, $falseBb);
        $context->builder->positionAtEnd($falseBb);
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($validBb);
        self::copyStringNodeValue($context, $node, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitStringNext(Context $context, Value $ht, Value $resultPtr): void
    {
        $node = self::stringPositionNode($context, $ht);
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $validBb = BasicBlockHelper::append($context, 'arr_ptr_snext_valid');
        $falseBb = BasicBlockHelper::append($context, 'arr_ptr_snext_false');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_snext_done');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $nextNode, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $falseBb, $validBb);
        $context->builder->positionAtEnd($falseBb);
        self::storeInternalPointer($context, $ht, self::constInvalid($context));
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($validBb);
        self::storeInternalPointerFromNode($context, $ht, $nextNode);
        self::copyStringNodeValue($context, $nextNode, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitStringPrev(Context $context, Value $ht, Value $resultPtr): void
    {
        $node = self::stringPositionNode($context, $ht);
        $head = self::loadStringHead($context, $ht);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $isHead = $context->builder->icmp(Builder::INT_EQ, $node, $head);
        $failBb = BasicBlockHelper::append($context, 'arr_ptr_sprev_fail');
        $walkBb = BasicBlockHelper::append($context, 'arr_ptr_sprev_walk');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_sprev_done');
        $context->builder->branchIf($isHead, $failBb, $walkBb);
        $context->builder->positionAtEnd($failBb);
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($walkBb);
        $prev = self::findPrevStringNode($context, $ht, $node);
        $validBb = BasicBlockHelper::append($context, 'arr_ptr_sprev_valid');
        $failBb2 = BasicBlockHelper::append($context, 'arr_ptr_sprev_fail2');
        self::branchIfStringNodeValid($context, $prev, $validBb, $failBb2);
        $context->builder->positionAtEnd($failBb2);
        self::storeInternalPointer($context, $ht, self::constInvalid($context));
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($validBb);
        self::storeInternalPointerFromNode($context, $ht, $prev);
        self::copyStringNodeValue($context, $prev, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitStringReset(Context $context, Value $ht, Value $resultPtr): void
    {
        $head = self::loadStringHead($context, $ht);
        $validBb = BasicBlockHelper::append($context, 'arr_ptr_sreset_valid');
        $failBb = BasicBlockHelper::append($context, 'arr_ptr_sreset_fail');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_sreset_done');
        self::branchIfStringNodeValid($context, $head, $validBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        self::storeInternalPointer($context, $ht, self::constInvalid($context));
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($validBb);
        self::storeInternalPointerFromNode($context, $ht, $head);
        self::copyStringNodeValue($context, $head, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitStringEnd(Context $context, Value $ht, Value $resultPtr): void
    {
        $last = self::findLastStringNode($context, $ht);
        $validBb = BasicBlockHelper::append($context, 'arr_ptr_send_valid');
        $failBb = BasicBlockHelper::append($context, 'arr_ptr_send_fail');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_send_done');
        self::branchIfStringNodeValid($context, $last, $validBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        self::storeInternalPointer($context, $ht, self::constInvalid($context));
        self::writeFalse($context, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($validBb);
        self::storeInternalPointerFromNode($context, $ht, $last);
        self::copyStringNodeValue($context, $last, $resultPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function stringPositionNode(Context $context, Value $ht): Value
    {
        $ip = self::loadInternalPointer($context, $ht);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $invalid = self::constInvalid($context);
        $isInvalid = $context->builder->icmp(Builder::INT_EQ, $ip, $invalid);
        $head = self::loadStringHead($context, $ht);
        $fromIp = $context->builder->intToPtr($ip, $nodePtrType);

        return $context->builder->select($isInvalid, $head, $fromIp);
    }

    private static function findNextPackedIndex(Context $context, Value $ht, Value $start): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $idxSlot = $context->builder->alloca($sizeT, 1, 'arr_ptr_find_next');
        $context->builder->store($start, $idxSlot);
        $headBb = BasicBlockHelper::append($context, 'arr_ptr_find_next_head');
        $bodyBb = BasicBlockHelper::append($context, 'arr_ptr_find_next_body');
        $foundBb = BasicBlockHelper::append($context, 'arr_ptr_find_next_found');
        $failBb = BasicBlockHelper::append($context, 'arr_ptr_find_next_fail');
        $context->builder->branch($headBb);
        $context->builder->positionAtEnd($headBb);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $failBb, $bodyBb);
        $incrBb = BasicBlockHelper::append($context, 'arr_ptr_find_next_incr');
        $context->builder->positionAtEnd($bodyBb);
        $present = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($present, $foundBb, $incrBb);
        $context->builder->positionAtEnd($incrBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($headBb);
        $context->builder->positionAtEnd($foundBb);
        $foundIdx = $context->builder->load($idxSlot);
        $mergeBb = BasicBlockHelper::append($context, 'arr_ptr_find_next_merge');
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($mergeBb);
        $result = $context->builder->phi($sizeT);
        $result->addIncoming($foundIdx, $foundBb);
        $result->addIncoming($nextFree, $failBb);

        return $result;
    }

    private static function findPrevPackedIndex(Context $context, Value $ht, Value $start): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $idxSlot = $context->builder->alloca($sizeT, 1, 'arr_ptr_find_prev');
        $context->builder->store($start, $idxSlot);
        $headBb = BasicBlockHelper::append($context, 'arr_ptr_find_prev_head');
        $bodyBb = BasicBlockHelper::append($context, 'arr_ptr_find_prev_body');
        $foundBb = BasicBlockHelper::append($context, 'arr_ptr_find_prev_found');
        $failBb = BasicBlockHelper::append($context, 'arr_ptr_find_prev_fail');
        $zero = $sizeT->constInt(0, false);
        $context->builder->branch($headBb);
        $context->builder->positionAtEnd($headBb);
        $idx = $context->builder->load($idxSlot);
        $atStart = $context->builder->icmp(Builder::INT_EQ, $idx, $zero);
        $context->builder->branchIf($atStart, $failBb, $bodyBb);
        $decrBb = BasicBlockHelper::append($context, 'arr_ptr_find_prev_decr');
        $context->builder->positionAtEnd($bodyBb);
        $present = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($present, $foundBb, $decrBb);
        $context->builder->positionAtEnd($decrBb);
        $context->builder->store($context->builder->sub($idx, $one), $idxSlot);
        $context->builder->branch($headBb);
        $context->builder->positionAtEnd($foundBb);
        $foundIdx = $context->builder->load($idxSlot);
        $mergeBb = BasicBlockHelper::append($context, 'arr_ptr_find_prev_merge');
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($mergeBb);
        $result = $context->builder->phi($sizeT);
        $result->addIncoming($foundIdx, $foundBb);
        $result->addIncoming($nextFree, $failBb);

        return $result;
    }

    private static function findPrevStringNode(Context $context, Value $ht, Value $target): Value
    {
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $head = self::loadStringHead($context, $ht);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'arr_ptr_sprev_walk');
        $prevSlot = $context->builder->alloca($nodePtrType, 1, 'arr_ptr_sprev_prev');
        $context->builder->store($head, $walkSlot);
        $context->builder->store($nodePtrType->constNull(), $prevSlot);
        $headBb = BasicBlockHelper::append($context, 'arr_ptr_sprev_loop_head');
        $bodyBb = BasicBlockHelper::append($context, 'arr_ptr_sprev_loop_body');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_sprev_loop_done');
        $context->builder->branch($headBb);
        $context->builder->positionAtEnd($headBb);
        $walk = $context->builder->load($walkSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $walk, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $doneBb, $bodyBb);
        $context->builder->positionAtEnd($bodyBb);
        $isTarget = $context->builder->icmp(Builder::INT_EQ, $walk, $target);
        $foundBb = BasicBlockHelper::append($context, 'arr_ptr_sprev_loop_found');
        $nextBb = BasicBlockHelper::append($context, 'arr_ptr_sprev_loop_next');
        $context->builder->branchIf($isTarget, $foundBb, $nextBb);
        $context->builder->positionAtEnd($nextBb);
        $context->builder->store($walk, $prevSlot);
        $next = $context->builder->load($context->builder->structGep($walk, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($headBb);
        $context->builder->positionAtEnd($foundBb);
        $prev = $context->builder->load($prevSlot);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($nodePtrType);
        $result->addIncoming($prev, $foundBb);
        $result->addIncoming($nodePtrType->constNull(), $headBb);

        return $result;
    }

    private static function findLastStringNode(Context $context, Value $ht): Value
    {
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $head = self::loadStringHead($context, $ht);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'arr_ptr_send_walk');
        $lastSlot = $context->builder->alloca($nodePtrType, 1, 'arr_ptr_send_last');
        $context->builder->store($head, $walkSlot);
        $context->builder->store($nodePtrType->constNull(), $lastSlot);
        $headBb = BasicBlockHelper::append($context, 'arr_ptr_send_loop_head');
        $bodyBb = BasicBlockHelper::append($context, 'arr_ptr_send_loop_body');
        $doneBb = BasicBlockHelper::append($context, 'arr_ptr_send_loop_done');
        $context->builder->branch($headBb);
        $context->builder->positionAtEnd($headBb);
        $walk = $context->builder->load($walkSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $walk, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $doneBb, $bodyBb);
        $context->builder->positionAtEnd($bodyBb);
        $context->builder->store($walk, $lastSlot);
        $next = $context->builder->load($context->builder->structGep($walk, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($headBb);
        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($lastSlot);
    }

    private static function branchIfPackedIndexValid(
        Context $context,
        Value $ht,
        Value $idx,
        $validBb,
        $failBb
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $inRange = $context->builder->icmp(Builder::INT_ULT, $idx, $nextFree);
        $rangeBb = BasicBlockHelper::append($context, 'arr_ptr_pidx_range');
        $context->builder->branchIf($inRange, $rangeBb, $failBb);
        $context->builder->positionAtEnd($rangeBb);
        $present = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($present, $validBb, $failBb);
    }

    private static function branchIfStringNodeValid(
        Context $context,
        Value $node,
        $validBb,
        $failBb
    ): void {
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $failBb, $validBb);
    }

    private static function copyPackedValueAt(Context $context, Value $ht, Value $idx, Value $resultPtr): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load($context->builder->structGep($ht, $map['values']));
        $entry = $context->builder->inBoundsGep($values, $idx);
        self::copyValueEntryToResult($context, $entry, $resultPtr);
    }

    private static function copyStringNodeValue(Context $context, Value $node, Value $resultPtr): void
    {
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $entry = $context->builder->structGep($node, $nodeMap['value']);
        self::copyValueEntryToResult($context, $entry, $resultPtr);
    }

    private static function copyValueEntryToResult(Context $context, Value $entry, Value $resultPtr): void
    {
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $tag = 'arr_ptr_copy_'.(string) ++self::$copySeq;
        $stringBlock = BasicBlockHelper::append($context, $tag.'_str');
        $longBlock = BasicBlockHelper::append($context, $tag.'_long');
        $doubleBlock = BasicBlockHelper::append($context, $tag.'_dbl');
        $boolBlock = BasicBlockHelper::append($context, $tag.'_bool');
        $nullBlock = BasicBlockHelper::append($context, $tag.'_null');
        $htBlock = BasicBlockHelper::append($context, $tag.'_ht');
        $objBlock = BasicBlockHelper::append($context, $tag.'_obj');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $afterString = BasicBlockHelper::append($context, $tag.'_after_str');
        $context->builder->branchIf($isString, $stringBlock, $afterString);
        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $resultPtr,
            $context->builder->call($context->lookupFunction('__string__separate'), $str)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_INTEGER, false)
        );
        $afterLong = BasicBlockHelper::append($context, $tag.'_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);
        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $resultPtr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_FLOAT, false)
        );
        $afterDouble = BasicBlockHelper::append($context, $tag.'_after_dbl');
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);
        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $resultPtr,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $entry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_BOOLEAN, false)
        );
        $afterBool = BasicBlockHelper::append($context, $tag.'_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);
        $context->builder->positionAtEnd($boolBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $resultPtr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_ARRAY, false)
        );
        $afterHt = BasicBlockHelper::append($context, $tag.'_after_ht');
        $context->builder->branchIf($isHt, $htBlock, $afterHt);
        $context->builder->positionAtEnd($htBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $resultPtr,
            $context->builder->call($context->lookupFunction('__value__readHashtable'), $entry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterHt);
        $isObj = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $context->builder->branchIf($isObj, $objBlock, $nullBlock);
        $context->builder->positionAtEnd($objBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $resultPtr,
            $context->builder->call($context->lookupFunction('__value__readObject'), $entry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $resultPtr);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
    }

    private static function writeFalse(Context $context, Value $resultPtr): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $resultPtr,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
    }

    private static function loadInternalPointer(Context $context, Value $ht): Value
    {
        $map = $context->structFieldMap['__hashtable__'];

        return $context->builder->load(
            $context->builder->structGep($ht, $map['internalPointer'])
        );
    }

    private static function storeInternalPointer(Context $context, Value $ht, Value $ip): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $context->builder->store(
            $ip,
            $context->builder->structGep($ht, $map['internalPointer'])
        );
    }

    private static function storeInternalPointerFromNode(Context $context, Value $ht, Value $node): void
    {
        self::storeInternalPointer(
            $context,
            $ht,
            $context->builder->ptrToInt($node, $context->getTypeFromString('int64'))
        );
    }

    private static function loadStringHead(Context $context, Value $ht): Value
    {
        $map = $context->structFieldMap['__hashtable__'];

        return $context->builder->load(
            $context->builder->structGep($ht, $map['strKeys'])
        );
    }

    private static function constInvalid(Context $context): Value
    {
        return $context->getTypeFromString('int64')->constInt(self::INVALID_INDEX, true);
    }

    /** @deprecated use constInvalid */
    public static function unsupported(Context $context, string $fn): Value
    {
        throw new \LogicException(
            \sprintf('%s() is not implemented for JIT in this compiler build (#4967)', $fn)
        );
    }
}

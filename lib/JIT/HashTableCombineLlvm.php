<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\array_combine;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for {@see \PHPCompiler\ext\standard\VmArray::combine()} (#27132).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayCombineJitHelper} returned a
 * PHP HashTable that is not a native `__hashtable__` — json_encode segfaults after
 * `c:main_before_php` (peer {@see ArrayFlipLlvm} / #26970, {@see HashTablePadLlvm} / #26971).
 *
 * Zips values from both tables in Zend iteration order (packed then string keys), writing
 * via the same helpers as {@see ArrayFlipLlvm} (no NestedJIT / no exportPairs intermediate).
 *
 * VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::combine()} /
 * {@see \PHPCompiler\ext\standard\ArrayCombineJitHelper}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_combine)
 */
final class HashTableCombineLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * @param Value $keysHt   __hashtable__* — values become result keys (iteration order)
     * @param Value $valuesHt __hashtable__* — values become result values (iteration order)
     */
    public static function combine(Context $context, Value $keysHt, Value $valuesHt): Value
    {
        $tag = (string) self::nextSeq();
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);

        $nKeys = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $keysHt
        );
        $nVals = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $valuesHt
        );

        $keysEmpty = $context->builder->icmp(Builder::INT_EQ, $nKeys, $zero);
        $valsEmpty = $context->builder->icmp(Builder::INT_EQ, $nVals, $zero);
        $bothEmpty = $context->builder->and($keysEmpty, $valsEmpty);
        $eitherEmpty = $context->builder->or($keysEmpty, $valsEmpty);
        $lengthMismatch = $context->builder->icmp(Builder::INT_NE, $nKeys, $nVals);
        $fail = $context->builder->or(
            $context->builder->and($eitherEmpty, $context->builder->not($bothEmpty)),
            $lengthMismatch
        );
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $context->builder->not($fail),
            'ht_combine_'.$tag,
            array_combine::LENGTH_MISMATCH_ERROR,
            'ok',
            'len_mismatch'
        );

        $dest = HashTableHelper::alloc($context);
        // Parallel packed walks are valid when both tables are packed lists of equal length
        // (the common / issue #27132 path). Mixed string-key sources use the value-list zip.
        $keysPairs = self::isLikelyPackedOnly($context, $keysHt);
        $valsPairs = self::isLikelyPackedOnly($context, $valuesHt);
        $bothPacked = $context->builder->and($keysPairs, $valsPairs);

        $packedBb = BasicBlockHelper::append($context, 'ht_combine_packed_'.$tag);
        $generalBb = BasicBlockHelper::append($context, 'ht_combine_general_'.$tag);
        $doneBb = BasicBlockHelper::append($context, 'ht_combine_done_'.$tag);
        $context->builder->branchIf($bothPacked, $packedBb, $generalBb);

        $context->builder->positionAtEnd($packedBb);
        self::zipPacked($context, $keysHt, $valuesHt, $dest);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($generalBb);
        self::zipViaValueLists($context, $keysHt, $valuesHt, $dest, $nKeys);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $dest;
    }

    /** True when the table has no string keys (packed / list-shaped). */
    private static function isLikelyPackedOnly(Context $context, Value $ht): Value
    {
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $head = $context->builder->load($context->builder->structGep($ht, $htMap['strKeys']));

        return $context->builder->icmp(Builder::INT_EQ, $head, $nodePtrTy->constNull());
    }

    private static function zipPacked(
        Context $context,
        Value $keysHt,
        Value $valuesHt,
        Value $dest
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load(
            $context->builder->structGep($keysHt, $htMap['nextFreeElement'])
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'ht_combine_zp_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_combine_zp_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'ht_combine_zp_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'ht_combine_zp_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_combine_zp_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $keySet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $keysHt,
            $idx
        );
        $valSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $valuesHt,
            $idx
        );
        $both = $context->builder->and($keySet, $valSet);
        $context->builder->branchIf($both, $take, $next);

        $context->builder->positionAtEnd($take);
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $keysHt, $idx);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $valuesHt, $idx);
        self::storeCombineKey($context, $dest, $keyVar, $valVar);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function zipViaValueLists(
        Context $context,
        Value $keysHt,
        Value $valuesHt,
        Value $dest,
        Value $nKeys
    ): void {
        $tag = (string) self::nextSeq();
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $keyList = self::valuesInIterationOrder($context, $keysHt);
        $valList = self::valuesInIterationOrder($context, $valuesHt);

        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'ht_combine_zg_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_combine_zg_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_combine_zg_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $nKeys);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $keyList, $idx);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $valList, $idx);
        self::storeCombineKey($context, $dest, $keyVar, $valVar);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function valuesInIterationOrder(Context $context, Value $src): Value
    {
        $dest = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $outIdxSlot);
        self::appendPackedValues($context, $src, $dest, $outIdxSlot);
        self::appendStringKeyValues($context, $src, $dest, $outIdxSlot);

        return $dest;
    }

    private static function appendPackedValues(
        Context $context,
        Value $src,
        Value $dest,
        Value $outIdxSlot
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'ht_combine_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_combine_pk_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'ht_combine_pk_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'ht_combine_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_combine_pk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $take, $next);

        $context->builder->positionAtEnd($take);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        $outIdx = $context->builder->load($outIdxSlot);
        HashTableHelper::setAtIndex($context, $dest, $outIdx, $valVar);
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outIdxSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function appendStringKeyValues(
        Context $context,
        Value $src,
        Value $dest,
        Value $outIdxSlot
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($src, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'ht_combine_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_combine_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'ht_combine_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_combine_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $valVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valSlot);
        $outIdx = $context->builder->load($outIdxSlot);
        HashTableHelper::setAtIndex($context, $dest, $outIdx, $valVar);
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outIdxSlot);

        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Zend array_combine / array_fill_keys key coercion — peer
     * {@see \PHPCompiler\ext\standard\VmArray::storeCombineKey()}.
     * String/int paths mirror {@see ArrayFlipLlvm::storeFlipped()}.
     * Public for {@see HashTableFillKeysLlvm} (#27127).
     */
    public static function storeCombineKey(
        Context $context,
        Value $dest,
        Variable $keyVar,
        Variable $valVar
    ): void {
        $tag = (string) self::nextSeq();
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $keyVar);
        $typeByte = $context->builder->load(
            $context->builder->structGep($keyPtr, $context->structFieldMap['__value__']['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $strBb = BasicBlockHelper::append($context, 'ht_combine_key_str_'.$tag);
        $longBb = BasicBlockHelper::append($context, 'ht_combine_key_long_'.$tag);
        $boolBb = BasicBlockHelper::append($context, 'ht_combine_key_bool_'.$tag);
        $nullBb = BasicBlockHelper::append($context, 'ht_combine_key_null_'.$tag);
        $floatBb = BasicBlockHelper::append($context, 'ht_combine_key_float_'.$tag);
        $htBb = BasicBlockHelper::append($context, 'ht_combine_key_ht_'.$tag);
        $illegalBb = BasicBlockHelper::append($context, 'ht_combine_key_illegal_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_combine_key_done_'.$tag);

        $afterStr = BasicBlockHelper::append($context, 'ht_combine_key_after_str_'.$tag);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $context->builder->branchIf($isString, $strBb, $afterStr);

        $context->builder->positionAtEnd($strBb);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        // Same write path as ArrayFlipLlvm::storeFlipped (json_encode-safe under thin AOT).
        HashTableHelper::setAtStringKey($context, $dest, $owned, $valVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterStr);
        $afterLong = BasicBlockHelper::append($context, 'ht_combine_key_after_long_'.$tag);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG & 0x7f, false)
        );
        $context->builder->branchIf($isLong, $longBb, $afterLong);

        $context->builder->positionAtEnd($longBb);
        $long = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $idx = $context->builder->truncOrBitCast($long, $sizeT);
        HashTableHelper::setAtIndex($context, $dest, $idx, $valVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'ht_combine_key_after_bool_'.$tag);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL & 0x7f, false)
        );
        $context->builder->branchIf($isBool, $boolBb, $afterBool);

        $context->builder->positionAtEnd($boolBb);
        $map = $context->structFieldMap['__value__'];
        $valueField = $context->builder->structGep($keyPtr, $map['value']);
        $boolByte = $context->builder->load(
            $context->builder->inBoundsGEP(
                $valueField,
                $context->getTypeFromString('int32')->constInt(0, false),
                $context->getTypeFromString('int64')->constInt(0, false)
            )
        );
        $isTrue = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
        $boolTrue = BasicBlockHelper::append($context, 'ht_combine_key_bool_t_'.$tag);
        $boolFalse = BasicBlockHelper::append($context, 'ht_combine_key_bool_f_'.$tag);
        $context->builder->branchIf($isTrue, $boolTrue, $boolFalse);
        $context->builder->positionAtEnd($boolTrue);
        HashTableHelper::setAtIndex($context, $dest, $sizeT->constInt(1, false), $valVar);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($boolFalse);
        $emptyKey = $context->builder->load($context->constantStringFromString(''));
        HashTableHelper::setAtStringKey($context, $dest, $emptyKey, $valVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $afterNull = BasicBlockHelper::append($context, 'ht_combine_key_after_null_'.$tag);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL & 0x7f, false)
        );
        $context->builder->branchIf($isNull, $nullBb, $afterNull);

        $context->builder->positionAtEnd($nullBb);
        $emptyNull = $context->builder->load($context->constantStringFromString(''));
        HashTableHelper::setAtStringKey($context, $dest, $emptyNull, $valVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterNull);
        $afterFloat = BasicBlockHelper::append($context, 'ht_combine_key_after_float_'.$tag);
        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_FLOAT & 0x7f, false)
        );
        $context->builder->branchIf($isFloat, $floatBb, $afterFloat);

        $context->builder->positionAtEnd($floatBb);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $keyPtr);
        $truncatedLong = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning($context, $doubleVal);
        HashTableHelper::setAtIndex(
            $context,
            $dest,
            $context->builder->truncOrBitCast($truncatedLong, $sizeT),
            $valVar
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterFloat);
        $afterHt = BasicBlockHelper::append($context, 'ht_combine_key_after_ht_'.$tag);
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $context->builder->branchIf($isHt, $htBb, $afterHt);

        $context->builder->positionAtEnd($htBb);
        $arrayKey = $context->builder->load($context->constantStringFromString('Array'));
        HashTableHelper::setAtStringKey($context, $dest, $arrayKey, $valVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterHt);
        $context->builder->branch($illegalBb);

        $context->builder->positionAtEnd($illegalBb);
        HashTableHelper::emitIllegalOffsetType($context);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * NestedJIT LLVM for HashTable in-place mutators (#24157).
 *
 * {@see \PHPCompiler\VM\HashTable::{replacePackedValues,assignPackedList,reorderKeyedPairs}}
 * must lower to LLVM during NestedJIT — not compile lib/VM/HashTable.php (#12910).
 *
 * NestedJIT materializes PHP `array` args as {@see __hashtable__*} (packed lists of
 * value boxes; keyed pairs are list-of-[key,value] pair hashtables — same shape as
 * {@see Call\HashTableExportKeyValuePairs}).
 *
 * php-src: ext/standard/array.c — php_usort / php_uasort / php_array_multisort reorder
 */
final class HashTableMutateNestedLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * Replace packed-list values in place (same length; indices 0..n-1).
     *
     * Implemented as clear + copy (same as {@see assignPackedList}): NestedJIT
     * setAtIndex overwrite without clear was a silent no-op / segfault under thin
     * AOT usort writeback (#26954 / peer #24157).
     *
     * @param Value $ht       receiver {@see __hashtable__*}
     * @param Value $valuesHt packed list of replacement values
     */
    public static function replacePackedValues(Context $context, Value $ht, Value $valuesHt): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_mut_rpl_cont');
        self::clearInPlace($context, $ht);
        self::copyPackedValuesOnto($context, $ht, $valuesHt, true);
    }

    /**
     * Replace packed-list contents with a new ordered value list (length may change).
     *
     * @param Value $ht       receiver {@see __hashtable__*}
     * @param Value $valuesHt packed list of values
     */
    public static function assignPackedList(Context $context, Value $ht, Value $valuesHt): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_mut_asg_cont');
        self::clearInPlace($context, $ht);
        self::copyPackedValuesOnto($context, $ht, $valuesHt, true);
    }

    /**
     * Replace associative contents from an ordered list of [key, value] pair hashtables.
     *
     * @param Value $ht      receiver {@see __hashtable__*}
     * @param Value $pairsHt packed list of pair hashtables (index 0=key, 1=value)
     */
    public static function reorderKeyedPairs(Context $context, Value $ht, Value $pairsHt): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_mut_rkp_cont');
        self::clearInPlace($context, $ht);

        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($pairsHt, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_mut_rkp_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_mut_rkp_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_mut_rkp_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $pairBox = HashTableReadLlvm::readIndexedToValueBox($context, $pairsHt, $idx);
        $pairHt = HashTableHelper::loadHashtablePointer($context, $pairBox);
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);
        HashTableHelper::setValueBoxKey($context, $ht, $keyVar, $valVar);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        // setValueBoxKey / setStringKey may leave numElements stale under thin AOT (#27217).
        // Pair list length is the authoritative post-reorder element count.
        $context->builder->store($count, $context->builder->structGep($ht, $map['numElements']));
    }

    /** Drop packed + string/object keys; keep capacity/values buffer for reuse. */
    private static function clearInPlace(Context $context, Value $ht): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $strPtrTy = $context->getTypeFromString('__strkey_node__*');
        $objPtrTy = $context->getTypeFromString('__objkey_node__*');
        $context->builder->store($zero, $context->builder->structGep($ht, $map['numElements']));
        $context->builder->store($zero, $context->builder->structGep($ht, $map['nextFreeElement']));
        $context->builder->store($strPtrTy->constNull(), $context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($objPtrTy->constNull(), $context->builder->structGep($ht, $map['objKeys']));
    }

    private static function copyPackedValuesOnto(
        Context $context,
        Value $ht,
        Value $valuesHt,
        bool $updateLength
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($valuesHt, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_mut_cpy_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_mut_cpy_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_mut_cpy_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $elem = HashTableReadLlvm::readIndexedToValueBox($context, $valuesHt, $idx);
        HashTableHelper::setAtIndex($context, $ht, $idx, $elem);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        if ($updateLength) {
            $context->builder->store($count, $context->builder->structGep($ht, $map['numElements']));
            $context->builder->store($count, $context->builder->structGep($ht, $map['nextFreeElement']));
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\strval;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Promote packed int indices to decimal string keys so key-preserving sorts can use
 * the strKeys linked list (AOT has no ordered int-key chain) (#33618).
 *
 * After promotion, {@see nextFreeElement} is 0 and foreach walks strKeys insertion order.
 * Echo of decimal keys matches Zend int-key printing for the natsort Done-when.
 */
final class HashTablePromotePackedToStringKeys
{
    public static function promote(Context $context, Value $ht): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_promote_packed_cont');
        $map = $context->structFieldMap['__hashtable__'];
        $valueMap = $context->structFieldMap['__value__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $n = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $done = BasicBlockHelper::append($context, 'ht_promote_done');
        $work = BasicBlockHelper::append($context, 'ht_promote_work');
        $empty = $context->builder->icmp(Builder::INT_EQ, $n, $zero);
        $context->builder->branchIf($empty, $done, $work);

        $context->builder->positionAtEnd($work);
        // Snapshot length; we clear packed before rewriting as strKeys.
        $count = $n;
        $valuesBase = $context->builder->load($context->builder->structGep($ht, $map['values']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        // Staging list of value boxes (preserve packed values across clear).
        $staging = HashTableHelper::alloc($context);
        $copyHead = BasicBlockHelper::append($context, 'ht_promote_copy_head');
        $copyBody = BasicBlockHelper::append($context, 'ht_promote_copy_body');
        $copyDone = BasicBlockHelper::append($context, 'ht_promote_copy_done');
        $context->builder->branch($copyHead);

        $context->builder->positionAtEnd($copyHead);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_UGE, $idx, $count);
        $context->builder->branchIf($past, $copyDone, $copyBody);

        $context->builder->positionAtEnd($copyBody);
        $entry = $context->builder->gep($valuesBase, $idx);
        $typeByte = $context->builder->load($context->builder->structGep($entry, $valueMap['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(0, false));
        $skip = BasicBlockHelper::append($context, 'ht_promote_copy_skip');
        $take = BasicBlockHelper::append($context, 'ht_promote_copy_take');
        $context->builder->branchIf($isNull, $skip, $take);

        $context->builder->positionAtEnd($take);
        $valBox = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $idx);
        $stageIdx = $context->builder->load(
            $context->builder->structGep($staging, $map['nextFreeElement'])
        );
        HashTableHelper::setAtIndex($context, $staging, $stageIdx, $valBox);
        $context->builder->store(
            $context->builder->addNoSignedWrap($stageIdx, $one),
            $context->builder->structGep($staging, $map['nextFreeElement'])
        );
        $context->builder->branch($skip);

        $context->builder->positionAtEnd($skip);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($copyHead);

        $context->builder->positionAtEnd($copyDone);
        // Clear packed + strKeys on receiver.
        $strPtrTy = $context->getTypeFromString('__strkey_node__*');
        $objPtrTy = $context->getTypeFromString('__objkey_node__*');
        $context->builder->store($zero, $context->builder->structGep($ht, $map['numElements']));
        $context->builder->store($zero, $context->builder->structGep($ht, $map['nextFreeElement']));
        $context->builder->store($strPtrTy->constNull(), $context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($objPtrTy->constNull(), $context->builder->structGep($ht, $map['objKeys']));

        // Rewrite staging values under decimal string keys of original indices.
        $context->builder->store($zero, $idxSlot);
        $writeHead = BasicBlockHelper::append($context, 'ht_promote_write_head');
        $writeBody = BasicBlockHelper::append($context, 'ht_promote_write_body');
        $context->builder->branch($writeHead);

        $context->builder->positionAtEnd($writeHead);
        $widx = $context->builder->load($idxSlot);
        $wPast = $context->builder->icmp(Builder::INT_UGE, $widx, $count);
        $context->builder->branchIf($wPast, $done, $writeBody);

        $context->builder->positionAtEnd($writeBody);
        $staged = HashTableReadLlvm::readIndexedToValueBox($context, $staging, $widx);
        // Build decimal key via strval(long box).
        $keySlot = JitValueBox::alloc($context);
        $keyPtr = JitValueBox::pointer($context, $keySlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $keyPtr,
            $context->builder->zExt($widx, $i64)
        );
        $keyStr = (new strval())->valueToString($context, $keyPtr);
        HashTableHelper::setAtStringKey($context, $ht, $keyStr, $staged);
        $context->builder->store($context->builder->addNoSignedWrap($widx, $one), $idxSlot);
        $context->builder->branch($writeHead);

        $context->builder->positionAtEnd($done);
    }
}

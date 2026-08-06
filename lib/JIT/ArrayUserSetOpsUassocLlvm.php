<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM array_udiff_uassoc()/array_uintersect_uassoc() for thin standalone AOT (#27243).
 *
 * Dual key+value match with spaceship-equivalent strcmp (strings) / int64 icmp (otherwise),
 * mirroring {@see ArrayUserSetOpsKeyLlvm}. NestedJIT of dual-closure helpers aborts under thin
 * AOT (same class as ukey NestedJIT — #27228).
 *
 * php-src: ext/standard/array.c — php_array_udiff_uassoc / php_array_uintersect_uassoc
 */
final class ArrayUserSetOpsUassocLlvm
{
    private static int $seq = 0;

    public static function filterByKeyValue(
        Context $context,
        bool $intersect,
        Value $firstHt,
        Value $othersPackedHt
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_uassoc_llvm_cont');
        $tag = (string) (++self::$seq);
        $prefix = ($intersect ? 'uintersect_uassoc' : 'udiff_uassoc').'_'.$tag;
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $dest = HashTableHelper::alloc($context);
        $firstPairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $firstHt);
        $firstCount = $context->builder->load(
            $context->builder->structGep($firstPairs, $htMap['nextFreeElement'])
        );
        $otherCount = $context->builder->load(
            $context->builder->structGep($othersPackedHt, $htMap['nextFreeElement'])
        );

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $iSlot);
        $head = BasicBlockHelper::append($context, $prefix.'_head');
        $body = BasicBlockHelper::append($context, $prefix.'_body');
        $done = BasicBlockHelper::append($context, $prefix.'_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $past = $context->builder->icmp(Builder::INT_UGE, $i, $firstCount);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyBox = self::pairOperand($context, $firstPairs, $i, $zero);
        $valBox = self::pairOperand($context, $firstPairs, $i, $one);
        $present = self::pairPresentInOthers(
            $context,
            $keyBox,
            $valBox,
            $othersPackedHt,
            $otherCount,
            $intersect,
            $prefix
        );
        $keep = $intersect
            ? $present
            : $context->builder->icmp(Builder::INT_EQ, $present, $i1->constInt(0, false));
        $keepBb = BasicBlockHelper::append($context, $prefix.'_keep');
        $nextBb = BasicBlockHelper::append($context, $prefix.'_next');
        $context->builder->branchIf($keep, $keepBb, $nextBb);

        $context->builder->positionAtEnd($keepBb);
        HashTableWriteLlvm::setValueBoxKey($context, $dest, $keyBox, $valBox);
        $context->builder->branch($nextBb);

        $context->builder->positionAtEnd($nextBb);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }

    /** @return Value int1 — true if key+value pair is in all others ($requireAll) or any other (!$requireAll) */
    private static function pairPresentInOthers(
        Context $context,
        Variable $needleKey,
        Variable $needleVal,
        Value $othersPackedHt,
        Value $otherCount,
        bool $requireAll,
        string $prefix
    ): Value {
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $resultSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store(
            $i1->constInt($requireAll ? 1 : 0, false),
            $resultSlot
        );
        $oSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $oSlot);

        $head = BasicBlockHelper::append($context, $prefix.'_oth_head');
        $body = BasicBlockHelper::append($context, $prefix.'_oth_body');
        $done = BasicBlockHelper::append($context, $prefix.'_oth_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $o = $context->builder->load($oSlot);
        $past = $context->builder->icmp(Builder::INT_UGE, $o, $otherCount);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $otherBox = HashTableReadLlvm::readIndexedToValueBox($context, $othersPackedHt, $o);
        $otherHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $otherBox)
        );
        $otherPairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $otherHt);
        $otherPairsCount = $context->builder->load(
            $context->builder->structGep($otherPairs, $htMap['nextFreeElement'])
        );
        $inThis = self::pairInPairList(
            $context,
            $needleKey,
            $needleVal,
            $otherPairs,
            $otherPairsCount,
            $prefix.'_scan'
        );
        if ($requireAll) {
            $context->builder->store(
                $context->builder->and($context->builder->load($resultSlot), $inThis),
                $resultSlot
            );
        } else {
            $context->builder->store(
                $context->builder->or($context->builder->load($resultSlot), $inThis),
                $resultSlot
            );
        }
        $context->builder->store($context->builder->addNoSignedWrap($o, $one), $oSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    /** @return Value int1 */
    private static function pairInPairList(
        Context $context,
        Variable $needleKey,
        Variable $needleVal,
        Value $pairs,
        Value $pairCount,
        string $prefix
    ): Value {
        $tag = (string) (++self::$seq);
        $prefix = $prefix.'_'.$tag;
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $foundSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(0, false), $foundSlot);
        $jSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $jSlot);

        $head = BasicBlockHelper::append($context, $prefix.'_pair_head');
        $body = BasicBlockHelper::append($context, $prefix.'_pair_body');
        $done = BasicBlockHelper::append($context, $prefix.'_pair_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $j = $context->builder->load($jSlot);
        $past = $context->builder->icmp(Builder::INT_UGE, $j, $pairCount);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $otherKey = self::pairOperand($context, $pairs, $j, $zero);
        $otherVal = self::pairOperand($context, $pairs, $j, $one);
        $keyCmp = ArrayUserSetOpsKeyLlvm::compareValueBoxesPublic($context, $needleKey, $otherKey);
        $keyEq = $context->builder->icmp(Builder::INT_EQ, $keyCmp, $i64->constInt(0, false));
        $keyHit = BasicBlockHelper::append($context, $prefix.'_key_hit');
        $keyMiss = BasicBlockHelper::append($context, $prefix.'_key_miss');
        $context->builder->branchIf($keyEq, $keyHit, $keyMiss);

        $context->builder->positionAtEnd($keyHit);
        $valCmp = ArrayUserSetOpsKeyLlvm::compareValueBoxesPublic($context, $needleVal, $otherVal);
        $valEq = $context->builder->icmp(Builder::INT_EQ, $valCmp, $i64->constInt(0, false));
        $bothHit = BasicBlockHelper::append($context, $prefix.'_both_hit');
        $valMiss = BasicBlockHelper::append($context, $prefix.'_val_miss');
        $context->builder->branchIf($valEq, $bothHit, $valMiss);

        $context->builder->positionAtEnd($bothHit);
        $context->builder->store($i1->constInt(1, false), $foundSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($valMiss);
        $context->builder->store($context->builder->addNoSignedWrap($j, $one), $jSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($keyMiss);
        $context->builder->store($context->builder->addNoSignedWrap($j, $one), $jSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    private static function pairOperand(
        Context $context,
        Value $pairs,
        Value $pairIndex,
        Value $cmpIndex
    ): Variable {
        $pairBox = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $pairIndex);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pairBox)
        );

        return HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $cmpIndex);
    }
}

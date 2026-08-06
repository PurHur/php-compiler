<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM array_diff_ukey()/array_intersect_ukey() for thin standalone AOT (#27228).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\ArrayUserSetOpsJitHelper} key filters aborts
 * under thin AOT (peer uksort {@see UsortKeyedLlvm} / #27217). Walk exported key/value pairs
 * and compare keys with spaceship-equivalent strcmp (strings) / int64 icmp (otherwise) —
 * NestedClosureInvoke string spaceship returns 0 under thin AOT.
 *
 * Comparison mirrors {@see UsortKeyedLlvm}: string → strcmp; otherwise → int64 icmp.
 * Do not call NestedClosureInvoke here — that path hangs / returns 0 for string spaceship.
 *
 * php-src: ext/standard/array.c — php_array_diff_ukey / php_array_intersect_ukey
 */
final class ArrayUserSetOpsKeyLlvm
{
    private static int $seq = 0;

    public static function filterByKey(
        Context $context,
        bool $intersect,
        Value $firstHt,
        Value $othersPackedHt
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_ukey_llvm_cont');
        $tag = (string) (++self::$seq);
        $prefix = ($intersect ? 'uintersect_ukey' : 'udiff_ukey').'_'.$tag;
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
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
        $present = self::keyPresentInOthers(
            $context,
            $keyBox,
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

    /** @return Value int1 — true if key is in all others ($requireAll) or any other (!$requireAll) */
    private static function keyPresentInOthers(
        Context $context,
        Variable $needleKey,
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
        $inThis = self::keyInPairList(
            $context,
            $needleKey,
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
    private static function keyInPairList(
        Context $context,
        Variable $needleKey,
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

        $head = BasicBlockHelper::append($context, $prefix.'_key_head');
        $body = BasicBlockHelper::append($context, $prefix.'_key_body');
        $done = BasicBlockHelper::append($context, $prefix.'_key_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $j = $context->builder->load($jSlot);
        $past = $context->builder->icmp(Builder::INT_UGE, $j, $pairCount);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $otherKey = self::pairOperand($context, $pairs, $j, $zero);
        $cmp = self::compareValueBoxes($context, $needleKey, $otherKey);
        $eq = $context->builder->icmp(Builder::INT_EQ, $cmp, $i64->constInt(0, false));
        $hit = BasicBlockHelper::append($context, $prefix.'_key_hit');
        $miss = BasicBlockHelper::append($context, $prefix.'_key_miss');
        $context->builder->branchIf($eq, $hit, $miss);

        $context->builder->positionAtEnd($hit);
        $context->builder->store($i1->constInt(1, false), $foundSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($miss);
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

    /** @return Value int64 spaceship-equivalent */
    private static function compareValueBoxes(Context $context, Variable $left, Variable $right): Value
    {
        $tag = (string) (++self::$seq);
        $leftPtr = JitValueBox::valuePtrFromVariable($context, $left);
        $rightPtr = JitValueBox::valuePtrFromVariable($context, $right);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $leftKind = $context->builder->and(
            $context->builder->load($context->builder->structGep($leftPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $strBb = $fn->appendBasicBlock('ukey_filter_cmp_str_'.$tag);
        $longBb = $fn->appendBasicBlock('ukey_filter_cmp_long_'.$tag);
        $join = $fn->appendBasicBlock('ukey_filter_cmp_join_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $zero = $i64->constInt(0, false);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $leftKind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $context->builder->branchIf($isString, $strBb, $longBb);

        $context->builder->positionAtEnd($strBb);
        $lStr = $context->builder->call($context->lookupFunction('__value__readString'), $leftPtr);
        $rStr = $context->builder->call($context->lookupFunction('__value__readString'), $rightPtr);
        $context->builder->store(JitStringCompare::strcmp($context, $lStr, $rStr), $resultSlot);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($longBb);
        $lLong = $context->builder->call($context->lookupFunction('__value__readLong'), $leftPtr);
        $rLong = $context->builder->call($context->lookupFunction('__value__readLong'), $rightPtr);
        $lt = $context->builder->icmp(Builder::INT_SLT, $lLong, $rLong);
        $gt = $context->builder->icmp(Builder::INT_SGT, $lLong, $rLong);
        $context->builder->store(
            $context->builder->select(
                $lt,
                $i64->constInt(-1, true),
                $context->builder->select($gt, $i64->constInt(1, false), $zero)
            ),
            $resultSlot
        );
        $context->builder->branch($join);

        $context->builder->positionAtEnd($join);

        return $context->builder->load($resultSlot);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPLLVM\Builder;
use PHPLLVM\Type;
use PHPLLVM\Value;

/**
 * Pure LLVM asort()/arsort() for thin standalone AOT (#33620, #34707).
 *
 * Export key/value pairs, bubble-sort by value, stringify integer keys, then
 * {@see HashTableMutateNestedLlvm::reorderKeyedPairs}. Integer keys must become
 * string keys on writeback: packed `values[index]` cannot express non-ascending
 * key order (peer {@see KeySortRuntime::krsortPackedListByKey} / #10836).
 *
 * SORT_STRING|SORT_FLAG_CASE uses {@see JitStringCompare::strcasecmp}.
 *
 * php-src: ext/standard/array.c — php_array_asort / php_array_arsort
 */
final class ValueSortKeyedLlvm
{
    /**
     * @param bool $caseInsensitive SORT_STRING|SORT_FLAG_CASE — ASCII strcasecmp (#34707)
     */
    public static function sortValuesPreserveKeys(
        Context $context,
        Value $ht,
        bool $reverse,
        bool $caseInsensitive = false
    ): void {
        $prefix = ($reverse ? 'arsort_keyed_llvm' : 'asort_keyed_llvm')
            .($caseInsensitive ? '_strcase' : '');
        BasicBlockHelper::ensureOpenInsertBlock($context, $prefix.'_cont');

        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $valueType = $context->getTypeFromString('__value__');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $valueIndex = $one;

        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $done = BasicBlockHelper::append($context, $prefix.'_done');
        $work = BasicBlockHelper::append($context, $prefix.'_work');
        $tooSmall = $context->builder->icmp(
            Builder::INT_ULT,
            $n,
            $sizeT->constInt(2, false)
        );
        $context->builder->branchIf($tooSmall, $done, $work);

        $context->builder->positionAtEnd($work);
        $pairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $ht);
        $pairCount = $context->builder->load(
            $context->builder->structGep($pairs, $map['nextFreeElement'])
        );
        $maxPasses = $context->builder->mul($pairCount, $pairCount);
        $passCountSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $passCountSlot);

        $swappedSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(1, false), $swappedSlot);
        $passHead = BasicBlockHelper::append($context, $prefix.'_pass_head');
        $passBody = BasicBlockHelper::append($context, $prefix.'_pass_body');
        $writeback = BasicBlockHelper::append($context, $prefix.'_writeback');
        $context->builder->branch($passHead);

        $context->builder->positionAtEnd($passHead);
        $didSwap = $context->builder->load($swappedSlot);
        $passCount = $context->builder->load($passCountSlot);
        $overCap = $context->builder->icmp(Builder::INT_UGE, $passCount, $maxPasses);
        $cont = $context->builder->and(
            $didSwap,
            $context->builder->icmp(Builder::INT_EQ, $overCap, $i1->constInt(0, false))
        );
        $context->builder->branchIf($cont, $passBody, $writeback);

        $context->builder->positionAtEnd($passBody);
        $context->builder->store(
            $context->builder->addNoSignedWrap($passCount, $one),
            $passCountSlot
        );
        $context->builder->store($i1->constInt(0, false), $swappedSlot);
        $limit = $context->builder->sub($pairCount, $one);
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $iSlot);
        $walkHead = BasicBlockHelper::append($context, $prefix.'_walk_head');
        $walkBody = BasicBlockHelper::append($context, $prefix.'_walk_body');
        $passExit = BasicBlockHelper::append($context, $prefix.'_pass_exit');
        $context->builder->branch($walkHead);

        $context->builder->positionAtEnd($walkHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $i, $limit);
        $context->builder->branchIf($atEnd, $passExit, $walkBody);

        $context->builder->positionAtEnd($walkBody);
        $j = $context->builder->addNoSignedWrap($i, $one);
        $leftBox = self::pairCompareOperand($context, $pairs, $i, $valueIndex);
        $rightBox = self::pairCompareOperand($context, $pairs, $j, $valueIndex);
        $cmpLong = self::compareValueBoxes($context, $leftBox, $rightBox, $caseInsensitive);
        $needsSwap = $reverse
            ? $context->builder->icmp(Builder::INT_SLT, $cmpLong, $i64->constInt(0, false))
            : $context->builder->icmp(Builder::INT_SGT, $cmpLong, $i64->constInt(0, false));
        $swapBlock = BasicBlockHelper::append($context, $prefix.'_swap');
        $advance = BasicBlockHelper::append($context, $prefix.'_advance');
        $context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $context->builder->positionAtEnd($swapBlock);
        self::swapPackedEntriesAt($context, $pairs, $i, $j, $valueType);
        $context->builder->store($i1->constInt(1, false), $swappedSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($walkHead);

        $context->builder->positionAtEnd($passExit);
        $context->builder->branch($passHead);

        $context->builder->positionAtEnd($writeback);
        // Packed AOT HTs store int keys in values[index] — foreach is always ascending
        // index order, so asort cannot express key order 1 then 0. Stringify int keys
        // (same trick as KeySortRuntime::krsortPackedListByKey / #10836) so writeback
        // walks strKeys in sorted insertion order (#33620).
        self::stringifyIntegerPairKeys($context, $pairs);
        HashTableMutateNestedLlvm::reorderKeyedPairs($context, $ht, $pairs);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Replace integer pair keys with their decimal string form so
     * {@see HashTableMutateNestedLlvm::reorderKeyedPairs} uses setAtStringKey.
     *
     * Shared with {@see UsortKeyedLlvm} writeback (#33620 / #33627).
     *
     * @param string $bbPrefix unique basic-block name prefix (caller must not collide)
     */
    public static function stringifyIntegerPairKeys(
        Context $context,
        Value $pairsHt,
        string $bbPrefix = 'vsort_strkey'
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $valueMap = $context->structFieldMap['__value__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($pairsHt, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, $bbPrefix.'_head');
        $body = BasicBlockHelper::append($context, $bbPrefix.'_body');
        $done = BasicBlockHelper::append($context, $bbPrefix.'_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $pairBox = HashTableReadLlvm::readIndexedToValueBox($context, $pairsHt, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pairBox)
        );
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $keyVar);
        $kind = $context->builder->and(
            $context->builder->load($context->builder->structGep($keyPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG & 0x7f, false)
        );
        $asStr = BasicBlockHelper::append($context, $bbPrefix.'_as_str');
        $advance = BasicBlockHelper::append($context, $bbPrefix.'_advance');
        $context->builder->branchIf($isLong, $asStr, $advance);

        $context->builder->positionAtEnd($asStr);
        $longKey = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $keyStr = JitNativeString::formatIndexKey($context, $longKey);
        $strBox = self::stringPtrValueBox($context, $keyStr);
        HashTableHelper::setAtIndex($context, $pairHt, $zero, $strBox);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function stringPtrValueBox(Context $context, Value $strPtr): Variable
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $strPtr
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    /** @return Value int64 */
    private static function compareValueBoxes(
        Context $context,
        Variable $left,
        Variable $right,
        bool $caseInsensitive = false
    ): Value {
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
        $tag = $caseInsensitive ? 'vsort_cmp_strcase' : 'vsort_cmp';
        $strBb = $fn->appendBasicBlock($tag.'_str');
        $longBb = $fn->appendBasicBlock($tag.'_long');
        $join = $fn->appendBasicBlock($tag.'_join');
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
        $strCmp = $caseInsensitive
            ? JitStringCompare::strcasecmp($context, $lStr, $rStr)
            : JitStringCompare::strcmp($context, $lStr, $rStr);
        $context->builder->store($strCmp, $resultSlot);
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

    private static function pairCompareOperand(
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

    private static function swapPackedEntriesAt(
        Context $context,
        Value $ht,
        Value $idxA,
        Value $idxB,
        Type $valueType
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load($context->builder->structGep($ht, $map['values']));
        $entryA = $context->builder->inBoundsGep($values, $idxA);
        $entryB = $context->builder->inBoundsGep($values, $idxB);
        $tmp = BasicBlockHelper::entryAlloca($context, $valueType);
        $context->builder->store($context->builder->load($entryA), $tmp);
        $context->builder->store($context->builder->load($entryB), $entryA);
        $context->builder->store($context->builder->load($tmp), $entryB);
    }
}

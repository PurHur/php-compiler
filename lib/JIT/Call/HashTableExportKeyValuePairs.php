<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** HashTable::exportKeyValuePairs for nested php-in-PHP JIT helpers (#12910).
 *
 * Packed numeric walk skips TYPE_UNDEFINED holes only — TYPE_NULL is emitted (#33639).
 */
final class HashTableExportKeyValuePairs implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('exportKeyValuePairs() requires a HashTable receiver');
        }
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);

        return self::exportPairs($context, $ht);
    }

    /** Ordered pair-list hashtable for NestedJIT slice / foreach (#23974 / #12908). */
    public static function exportPairsForSlice(Context $context, Value $ht): Value
    {
        return self::exportPairs($context, $ht);
    }

    /**
     * Foreach insertion-order export for keyed array_splice (#13573 / #34977).
     */
    public static function exportPairsInForeachOrder(Context $context, Value $ht): Value
    {
        $result = HashTableHelper::alloc($context);
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('size_t'));
        $context->builder->store($context->getTypeFromString('size_t')->constInt(0, false), $outIdxSlot);

        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $prefixEnd = $context->builder->load($context->builder->structGep($ht, $htMap['packedPrefixEnd']));
        $nextFree = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $numElements = $context->builder->load($context->builder->structGep($ht, $htMap['numElements']));
        // Sparse int-key arrays (array_diff filter, array_splice holes) may have
        // numElements < nextFreeElement — raw sub wraps and json_encode drops tail keys (#23593).
        $strCountRaw = $context->builder->sub($numElements, $nextFree);
        $hasStrRegion = $context->builder->icmp(Builder::INT_UGE, $numElements, $nextFree);
        $strCount = $context->builder->select($hasStrRegion, $strCountRaw, $zero);
        $totalPos = $context->builder->add($nextFree, $strCount);

        $posSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $posSlot);
        $tag = (string) spl_object_id($context);

        $head = BasicBlockHelper::append($context, 'ht_expins_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_expins_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_expins_done_'.$tag);
        $skip = BasicBlockHelper::append($context, 'ht_expins_skip_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $linearPos = $context->builder->load($posSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $linearPos, $totalPos);
        $context->builder->branchIf($pastEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $inPrefix = $context->builder->icmp(Builder::INT_ULT, $linearPos, $prefixEnd);
        $strEnd = $context->builder->add($prefixEnd, $strCount);
        $inStr = $context->builder->icmp(Builder::INT_ULT, $linearPos, $strEnd);

        $prefixBb = BasicBlockHelper::append($context, 'ht_expins_prefix_'.$tag);
        $routeLate = BasicBlockHelper::append($context, 'ht_expins_route_late_'.$tag);
        $strBb = BasicBlockHelper::append($context, 'ht_expins_str_'.$tag);
        $lateBb = BasicBlockHelper::append($context, 'ht_expins_late_'.$tag);
        $context->builder->branchIf($inPrefix, $prefixBb, $routeLate);

        $context->builder->positionAtEnd($routeLate);
        $context->builder->branchIf($inStr, $strBb, $lateBb);

        $context->builder->positionAtEnd($prefixBb);
        $isUndef = HashTableReadLlvm::packedIndexIsUndefined($context, $ht, $linearPos);
        $prefixTake = BasicBlockHelper::append($context, 'ht_expins_ptake_'.$tag);
        $context->builder->branchIf($isUndef, $skip, $prefixTake);
        $context->builder->positionAtEnd($prefixTake);
        $keyVar = self::longValueBox($context, JitNestedHelperCoerce::scalarToI64($context, $linearPos, $sizeT));
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $linearPos);
        self::appendPair($context, $result, $outIdxSlot, $keyVar, $valVar);
        $context->builder->branch($skip);

        $context->builder->positionAtEnd($strBb);
        self::appendStringKeyPairAtOrd(
            $context,
            $ht,
            $result,
            $outIdxSlot,
            $context->builder->sub($linearPos, $prefixEnd),
            $htMap,
            $nodeMap,
            $nodePtrTy,
            $skip,
            $tag
        );

        $context->builder->positionAtEnd($lateBb);
        $lateOffset = $context->builder->sub($context->builder->sub($linearPos, $prefixEnd), $strCount);
        $lateIdx = $context->builder->add($prefixEnd, $lateOffset);
        $lateUndef = HashTableReadLlvm::packedIndexIsUndefined($context, $ht, $lateIdx);
        $lateTake = BasicBlockHelper::append($context, 'ht_expins_ltake_'.$tag);
        $context->builder->branchIf($lateUndef, $skip, $lateTake);
        $context->builder->positionAtEnd($lateTake);
        $keyVar = self::longValueBox($context, JitNestedHelperCoerce::scalarToI64($context, $lateIdx, $sizeT));
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $lateIdx);
        self::appendPair($context, $result, $outIdxSlot, $keyVar, $valVar);
        $context->builder->branch($skip);

        $context->builder->positionAtEnd($skip);
        $context->builder->store($context->builder->addNoSignedWrap($linearPos, $one), $posSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $result;
    }

    private static function appendStringKeyPairAtOrd(
        Context $context,
        Value $ht,
        Value $result,
        Value $outIdxSlot,
        Value $ord,
        array $htMap,
        array $nodeMap,
        $nodePtrTy,
        $skipBb,
        string $tag
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $remSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($ht, $htMap['strKeys'])),
            $nodeSlot
        );
        $context->builder->store($ord, $remSlot);

        $wh = BasicBlockHelper::append($context, 'ht_expins_swh_'.$tag);
        $wb = BasicBlockHelper::append($context, 'ht_expins_swb_'.$tag);
        $wtake = BasicBlockHelper::append($context, 'ht_expins_swtake_'.$tag);
        $wadv = BasicBlockHelper::append($context, 'ht_expins_swadv_'.$tag);
        $context->builder->branch($wh);

        $context->builder->positionAtEnd($wh);
        $node = $context->builder->load($nodeSlot);
        $remaining = $context->builder->load($remSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $atTarget = $context->builder->icmp(Builder::INT_EQ, $remaining, $zero);
        $context->builder->branchIf($nodeNull, $skipBb, $wb);

        $context->builder->positionAtEnd($wb);
        $context->builder->branchIf($atTarget, $wtake, $wadv);

        $context->builder->positionAtEnd($wtake);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keyVar = self::stringPtrValueBox($context, $keyStr);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valVar = self::valueBoxFromEntry($context, $valField);
        self::appendPair($context, $result, $outIdxSlot, $keyVar, $valVar);
        $context->builder->branch($skipBb);

        $context->builder->positionAtEnd($wadv);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->store($context->builder->sub($remaining, $one), $remSlot);
        $context->builder->branch($wh);
    }

    private static function exportPairs(Context $context, Value $ht): Value
    {
        $result = HashTableHelper::alloc($context);
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('size_t'));
        $context->builder->store($context->getTypeFromString('size_t')->constInt(0, false), $outIdxSlot);

        self::exportNumericKeys($context, $ht, $result, $outIdxSlot);
        self::exportStringKeys($context, $ht, $result, $outIdxSlot);

        return $result;
    }

    private static function exportNumericKeys(
        Context $context,
        Value $ht,
        Value $result,
        Value $outIdxSlot
    ): void {
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $indexSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $limit = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $context->builder->store($zero, $indexSlot);
        $valuesBase = $context->builder->load($context->builder->structGep($ht, $htMap['values']));

        $head = BasicBlockHelper::append($context, 'ht_export_num_head');
        $body = BasicBlockHelper::append($context, 'ht_export_num_body');
        $done = BasicBlockHelper::append($context, 'ht_export_num_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($indexSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $limit);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $valueMap = $context->structFieldMap['__value__'];
        $entry = $context->builder->gep($valuesBase, $idx);
        $typeByte = $context->builder->load($context->builder->structGep($entry, $valueMap['type']));
        // Skip packed TYPE_UNDEFINED holes only — TYPE_NULL is a real element (#27536).
        // Skipping kind==0 dropped nulls from NestedJIT serialize / SplFixedArray (#33639).
        $isUndef = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_UNDEFINED & 0xff, false)
        );
        $skipBb = BasicBlockHelper::append($context, 'ht_export_num_skip');
        $addBb = BasicBlockHelper::append($context, 'ht_export_num_add');
        $context->builder->branchIf($isUndef, $skipBb, $addBb);

        $context->builder->positionAtEnd($addBb);
        $keyVar = self::longValueBox($context, $context->builder->zExt($idx, $i64));
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $idx);
        self::appendPair($context, $result, $outIdxSlot, $keyVar, $valVar);
        $context->builder->branch($skipBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $indexSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
    }

    private static function exportStringKeys(
        Context $context,
        Value $ht,
        Value $result,
        Value $outIdxSlot
    ): void {
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($ht, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'ht_export_str_head');
        $body = BasicBlockHelper::append($context, 'ht_export_str_body');
        $done = BasicBlockHelper::append($context, 'ht_export_str_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        // Own a copy of the node key — VarExportArrayLlvm / PrintRArrayLlvm quote paths
        // must not alias the live HT strKeys buffer (var_export then print_r showed every
        // key as the last key, #34514). Foreach / json_encode keep the direct load (#26367).
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $ownedKey = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $keyStr
        );
        $keyVar = self::stringPtrValueBox($context, $ownedKey);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valVar = self::valueBoxFromEntry($context, $valField);
        self::appendPair($context, $result, $outIdxSlot, $keyVar, $valVar);

        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
    }

    public static function appendPairToList(
        Context $context,
        Value $pairList,
        Value $outIdxSlot,
        Variable $keyVar,
        Variable $valVar
    ): void {
        self::appendPair($context, $pairList, $outIdxSlot, $keyVar, $valVar);
    }

    private static function appendPair(
        Context $context,
        Value $result,
        Value $outIdxSlot,
        Variable $keyVar,
        Variable $valVar
    ): void {
        $resolvedVal = self::copyValueBox($context, $valVar);
        $pairHt = HashTableHelper::alloc($context);
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        $one = $context->getTypeFromString('size_t')->constInt(1, false);
        HashTableHelper::setAtIndex($context, $pairHt, $zero, $keyVar);
        HashTableHelper::setAtIndex($context, $pairHt, $one, $resolvedVal);
        $outIdx = $context->builder->load($outIdxSlot);
        HashTableHelper::setAtIndex(
            $context,
            $result,
            $outIdx,
            new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $pairHt)
        );
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outIdxSlot);
    }

    private static function copyValueBox(Context $context, Variable $valVar): Variable
    {
        if (Variable::TYPE_VALUE === $valVar->type && Variable::KIND_VARIABLE === $valVar->kind) {
            return $valVar;
        }
        $srcPtr = JitValueBox::valuePtrFromVariable($context, $valVar);
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $srcPtr);
        // Keep JIT TYPE_NATIVE_BOOL (=2). Remapping to VM TYPE_BOOLEAN (=3) made
        // JitJsonEncode::encodeBoxedValue treat the value as TYPE_NATIVE_DOUBLE (#33520).

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function valueBoxFromEntry(Context $context, Value $entryPtr): Variable
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $entryPtr);
        // Do not remap TYPE_NATIVE_BOOL → TYPE_BOOLEAN — tag 3 is NATIVE_DOUBLE in encodeBoxedValue (#33520).

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function longValueBox(Context $context, Value $long): Variable
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $long
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function stringValueBox(Context $context, Value $ptr, Value $len): Variable
    {
        $slot = JitValueBox::alloc($context);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $ptr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $str
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    /** Box an owned/separated `__string__*` without i8* re-init (NestedJIT-safe, #26977). */
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
}

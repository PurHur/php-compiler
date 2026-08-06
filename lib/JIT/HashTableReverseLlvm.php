<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * NestedJIT / call-site LLVM for {@see \PHPCompiler\VM\HashTable::reverseCopy()} (#27067, #27130).
 *
 * Must not call {@see Builtin\ArrayReverseRuntime} — NestedJIT of
 * {@see \PHPCompiler\ext\standard\ArrayReverseJitHelper} would recurse
 * (peer {@see HashTableSliceLlvm} / {@see HashTableCowLlvm}).
 *
 * Thin AOT: walk packed slots + {@see strKeys} directly (peer {@see HashTableMergeLlvm} /
 * {@see HashTableValuesLlvm} / {@see ArrayFlipLlvm}). Avoid
 * {@see Call\HashTableExportKeyValuePairs} nested pair hashtables — NestedJIT
 * json_encode sees those results as empty `{}` (#27130; peer #27076 note).
 *
 * Forward order is packed then string keys; reverse emits string keys (list reversed)
 * then packed indices high→low. After packed reindex, sync numElements/nextFreeElement
 * like {@see HashTableWriteLlvm::materializeNativeArrayForCall()} so isPackedList()
 * and exportKeyValuePairs agree with index/foreach.
 *
 * php-src: ext/standard/array.c — php_array_reverse()
 * String keys are always preserved; int keys re-index unless $preserve_keys.
 */
final class HashTableReverseLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /** @param Value $preserveKeys i1 */
    public static function reverse(Context $context, Value $srcHt, Value $preserveKeys): Value
    {
        $dest = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $htMap = $context->structFieldMap['__hashtable__'];
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $outIdxSlot);

        // Reverse of (packed then string): string keys first (list reversed), then packed.
        self::appendStringKeysReversed($context, $dest, $srcHt);
        self::appendPackedReversed($context, $dest, $srcHt, $preserveKeys, $outIdxSlot);

        // Sync packed-list metadata after reindex writes (peer materializeNativeArrayForCall).
        // Without this, NestedJIT isPackedList/exportKeyValuePairs disagree with index access
        // and json_encode prints `{}` (#27130).
        $reindexDone = BasicBlockHelper::append($context, 'ht_rev_meta_reidx_'.self::nextSeq());
        $preserveDone = BasicBlockHelper::append($context, 'ht_rev_meta_keep_'.self::nextSeq());
        $metaJoin = BasicBlockHelper::append($context, 'ht_rev_meta_join_'.self::nextSeq());
        $context->builder->branchIf($preserveKeys, $preserveDone, $reindexDone);

        $context->builder->positionAtEnd($reindexDone);
        $outIdx = $context->builder->load($outIdxSlot);
        $context->builder->store($outIdx, $context->builder->structGep($dest, $htMap['numElements']));
        $context->builder->store($outIdx, $context->builder->structGep($dest, $htMap['nextFreeElement']));
        $context->builder->branch($metaJoin);

        $context->builder->positionAtEnd($preserveDone);
        $context->builder->branch($metaJoin);

        $context->builder->positionAtEnd($metaJoin);

        return $dest;
    }

    private static function appendPackedReversed(
        Context $context,
        Value $dest,
        Value $srcHt,
        Value $preserve,
        Value $outIdxSlot
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');

        $nextFree = $context->builder->load(
            $context->builder->structGep($srcHt, $htMap['nextFreeElement'])
        );
        $nextFreeI64 = JitNestedHelperCoerce::scalarToI64($context, $nextFree, $sizeT);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($context->builder->sub($nextFreeI64, $i64->constInt(1, false)), $idxSlot);

        $head = BasicBlockHelper::append($context, 'ht_rev_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_rev_pk_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'ht_rev_pk_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'ht_rev_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_rev_pk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $pastStart = $context->builder->icmp(Builder::INT_SLT, $idx, $i64->constInt(0, false));
        $context->builder->branchIf($pastStart, $done, $body);

        $context->builder->positionAtEnd($body);
        $idxSize = JitNestedHelperCoerce::i64ToScalar($context, $idx, $sizeT);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $srcHt,
            $idxSize
        );
        $context->builder->branchIf($isSet, $take, $next);

        $context->builder->positionAtEnd($take);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $srcHt, $idxSize);
        self::writeReversedIntKey($context, $dest, $idxSize, $valVar, $preserve, $outIdxSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->sub($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Emit string-key entries in reverse list order (parallel key/value packed lists).
     */
    private static function appendStringKeysReversed(
        Context $context,
        Value $dest,
        Value $srcHt
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $keys = HashTableHelper::alloc($context);
        $vals = HashTableHelper::alloc($context);
        $countSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $countSlot);

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($srcHt, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $collectHead = BasicBlockHelper::append($context, 'ht_rev_sk_collect_head_'.$tag);
        $collectBody = BasicBlockHelper::append($context, 'ht_rev_sk_collect_body_'.$tag);
        $collectDone = BasicBlockHelper::append($context, 'ht_rev_sk_collect_done_'.$tag);
        $context->builder->branch($collectHead);

        $context->builder->positionAtEnd($collectHead);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $collectDone, $collectBody);

        $context->builder->positionAtEnd($collectBody);
        $count = $context->builder->load($countSlot);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keyOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $keyStr
        );
        $keySlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $keySlot),
            $keyOwned
        );
        $keyVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $keySlot);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $valVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valSlot);
        HashTableHelper::setAtIndex($context, $keys, $count, $keyVar);
        HashTableHelper::setAtIndex($context, $vals, $count, $valVar);
        $context->builder->store($context->builder->addNoSignedWrap($count, $one), $countSlot);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($collectHead);

        $context->builder->positionAtEnd($collectDone);
        $num = $context->builder->load($countSlot);
        $numI64 = JitNestedHelperCoerce::scalarToI64($context, $num, $sizeT);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($context->builder->sub($numI64, $i64->constInt(1, false)), $idxSlot);

        $emitHead = BasicBlockHelper::append($context, 'ht_rev_sk_emit_head_'.$tag);
        $emitBody = BasicBlockHelper::append($context, 'ht_rev_sk_emit_body_'.$tag);
        $emitDone = BasicBlockHelper::append($context, 'ht_rev_sk_emit_done_'.$tag);
        $context->builder->branch($emitHead);

        $context->builder->positionAtEnd($emitHead);
        $idx = $context->builder->load($idxSlot);
        $pastStart = $context->builder->icmp(Builder::INT_SLT, $idx, $i64->constInt(0, false));
        $context->builder->branchIf($pastStart, $emitDone, $emitBody);

        $context->builder->positionAtEnd($emitBody);
        $idxSize = JitNestedHelperCoerce::i64ToScalar($context, $idx, $sizeT);
        $keyBox = HashTableReadLlvm::readIndexedToValueBox($context, $keys, $idxSize);
        $valBox = HashTableReadLlvm::readIndexedToValueBox($context, $vals, $idxSize);
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $keyBox);
        $keyOwnedOut = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $keyPtr
        );
        HashTableWriteLlvm::setAtStringKey($context, $dest, $keyOwnedOut, $valBox);
        $context->builder->store($context->builder->sub($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($emitHead);

        $context->builder->positionAtEnd($emitDone);
    }

    private static function writeReversedIntKey(
        Context $context,
        Value $dest,
        Value $srcIdx,
        Variable $valVar,
        Value $preserve,
        Value $outIdxSlot
    ): void {
        $tag = (string) self::nextSeq();
        $sizeT = $context->getTypeFromString('size_t');
        $keep = BasicBlockHelper::append($context, 'ht_rev_keep_'.$tag);
        $reindex = BasicBlockHelper::append($context, 'ht_rev_reidx_'.$tag);
        $join = BasicBlockHelper::append($context, 'ht_rev_key_join_'.$tag);
        $context->builder->branchIf($preserve, $keep, $reindex);

        $context->builder->positionAtEnd($keep);
        HashTableHelper::setAtIndex($context, $dest, $srcIdx, $valVar);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($reindex);
        // Write at outIdx then bump — final metadata sync makes NestedJIT json_encode see a
        // dense packed list (#27130). Do not rely solely on setAtIndex nextFree updates.
        $outIdx = $context->builder->load($outIdxSlot);
        HashTableHelper::setAtIndex($context, $dest, $outIdx, $valVar);
        $context->builder->store(
            $context->builder->addNoSignedWrap($outIdx, $sizeT->constInt(1, false)),
            $outIdxSlot
        );
        $context->builder->branch($join);

        $context->builder->positionAtEnd($join);
    }
}
